<?php

namespace App\Console\Commands;

use App\Models\GoldenCase;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;

class JudgeReview extends Command
{
    protected $signature = 'triage:judge-review
        {--report= : Summary report JSON (default: latest storage/eval-reports/summary-*.json)}
        {--n=15 : How many summaries to hand-score}
        {--dump : Non-interactive: show the sample (incl. judge scores) and exit}';

    protected $description = 'Judge-the-judge: hand-score a sample of summaries and measure agreement with the LLM judge';

    public function handle(): int
    {
        $report = $this->option('report') ?: collect(glob(base_path('storage/eval-reports/summary-*.json')))->last();
        if (! $report || ! is_file($report)) {
            $this->error('No summary report found. Run triage:eval-summary first.');

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($report), true);
        $results = $data['results'] ?? [];
        if ($results === []) {
            $this->error('Report has no results.');

            return self::FAILURE;
        }

        // Deterministic spread across the report for coverage.
        $n = min((int) $this->option('n'), count($results));
        $step = max(1, intdiv(count($results), $n));
        $sample = [];
        for ($i = 0; count($sample) < $n && $i < count($results); $i += $step) {
            $sample[] = $results[$i];
        }
        $evidence = GoldenCase::query()->pluck('input', 'case_id');

        if ($this->option('dump')) {
            foreach ($sample as $r) {
                $this->line("<info>{$r['id']}</info>  judge: f={$r['faithfulness']} c={$r['completeness']}");
                $this->line('  summary: '.$r['summary']);
            }
            $this->info(count($sample).' sampled from '.basename($report));

            return self::SUCCESS;
        }

        intro("Judge-the-judge: score {$n} summaries. You will NOT see the judge's scores until the end.");
        $rows = [];
        foreach ($sample as $idx => $r) {
            $in = $evidence[$r['id']] ?? [];
            $ev = trim(
                'Message: '.($in['message'] ?? '—')."\n".
                'Root: '.($in['root_exception'] ?? '—')."\n".
                'Details: '.collect($in['error_detail'] ?? [])->map(fn ($d) => '['.($d['code'] ?? '?').'] '.($d['description'] ?? ''))->implode(' | ')
            );
            note("EVIDENCE\n{$ev}\n\nSUMMARY\n{$r['summary']}", 'Case '.($idx + 1)."/{$n} · {$r['id']}");

            $f = (int) select('Faithfulness (1=hallucinated … 5=fully grounded)', [1, 2, 3, 4, 5], default: 4);
            $c = (int) select('Completeness (1=misses root cause … 5=operation+entity+root)', [1, 2, 3, 4, 5], default: 4);

            $rows[] = [
                'id' => $r['id'],
                'human_faithfulness' => $f, 'judge_faithfulness' => (int) $r['faithfulness'],
                'human_completeness' => $c, 'judge_completeness' => (int) $r['completeness'],
            ];
        }

        $faithStats = $this->agreement(array_column($rows, 'human_faithfulness'), array_column($rows, 'judge_faithfulness'));
        $compStats = $this->agreement(array_column($rows, 'human_completeness'), array_column($rows, 'judge_completeness'));

        $out = [
            'timestamp' => Carbon::now()->toIso8601String(),
            'kind' => 'judge-validation',
            'summary_report' => basename($report),
            'n' => count($rows),
            'faithfulness' => $faithStats,
            'completeness' => $compStats,
            'scores' => $rows,
        ];
        $path = base_path('storage/eval-reports/judge-validation-'.Carbon::now()->format('Ymd-His').'.json');
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->table(
            ['Axis', 'Exact', 'Within-1', 'Mean bias (judge−you)', 'Quadratic-weighted κ', 'Verdict'],
            [
                ['faithfulness', $this->pct($faithStats['exact']), $this->pct($faithStats['within_1']), sprintf('%+.2f', $faithStats['bias']), sprintf('%.2f', $faithStats['qwk']), $this->kappaVerdict($faithStats['qwk'])],
                ['completeness', $this->pct($compStats['exact']), $this->pct($compStats['within_1']), sprintf('%+.2f', $compStats['bias']), sprintf('%.2f', $compStats['qwk']), $this->kappaVerdict($compStats['qwk'])],
            ]
        );
        outro("Saved → {$path}");
        $this->line('<comment>Rule of thumb:</comment> within-1 ≥ ~90% and κ ≥ ~0.6 → trust the judge. Positive bias = judge is more lenient than you.');

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $human
     * @param  list<int>  $judge
     * @return array{exact:float, within_1:float, mae:float, bias:float, qwk:float}
     */
    private function agreement(array $human, array $judge): array
    {
        $n = count($human);
        if ($n === 0) {
            return ['exact' => 0, 'within_1' => 0, 'mae' => 0, 'bias' => 0, 'qwk' => 0];
        }
        $exact = $within = 0;
        $absSum = $diffSum = 0;
        for ($i = 0; $i < $n; $i++) {
            $d = $judge[$i] - $human[$i];
            $exact += $d === 0 ? 1 : 0;
            $within += abs($d) <= 1 ? 1 : 0;
            $absSum += abs($d);
            $diffSum += $d;
        }

        return [
            'exact' => round($exact / $n, 3),
            'within_1' => round($within / $n, 3),
            'mae' => round($absSum / $n, 3),
            'bias' => round($diffSum / $n, 3),
            'qwk' => round($this->quadraticWeightedKappa($human, $judge), 3),
        ];
    }

    /** Quadratic-weighted Cohen's kappa on a 1..k ordinal scale. */
    private function quadraticWeightedKappa(array $human, array $judge, int $k = 5): float
    {
        $n = count($human);
        if ($n === 0) {
            return 0.0;
        }
        $observed = [];
        $hMarg = array_fill(1, $k, 0);
        $jMarg = array_fill(1, $k, 0);
        for ($i = 1; $i <= $k; $i++) {
            for ($j = 1; $j <= $k; $j++) {
                $observed[$i][$j] = 0;
            }
        }
        for ($x = 0; $x < $n; $x++) {
            $h = $human[$x];
            $j = $judge[$x];
            if ($h < 1 || $h > $k || $j < 1 || $j > $k) {
                continue;
            }
            $observed[$h][$j]++;
            $hMarg[$h]++;
            $jMarg[$j]++;
        }
        $num = $den = 0.0;
        for ($i = 1; $i <= $k; $i++) {
            for ($j = 1; $j <= $k; $j++) {
                $w = (($i - $j) ** 2) / (($k - 1) ** 2);
                $expected = $hMarg[$i] * $jMarg[$j] / $n;
                $num += $w * $observed[$i][$j];
                $den += $w * $expected;
            }
        }

        return $den == 0.0 ? 1.0 : 1 - ($num / $den);
    }

    private function kappaVerdict(float $k): string
    {
        return match (true) {
            $k >= 0.8 => 'very good',
            $k >= 0.6 => 'good',
            $k >= 0.4 => 'moderate',
            default => 'weak — do not trust',
        };
    }

    private function pct(float $v): string
    {
        return number_format($v * 100, 0).'%';
    }
}
