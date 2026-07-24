<?php

namespace App\Console\Commands;

use App\Models\GoldenCase;
use App\Services\Triage\FailureSummarizer;
use App\Services\Triage\SummaryJudge;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunSummaryEval extends Command
{
    protected $signature = 'triage:eval-summary
        {--only-reviewed : Only cases with reviewed=true}
        {--limit=0 : Cap number of cases (0 = all)}
        {--out=storage/eval-reports : Report directory}';

    protected $description = 'Generate incident summaries and score them with an LLM judge (faithfulness + completeness)';

    public function handle(FailureSummarizer $summarizer, SummaryJudge $judge): int
    {
        $cases = GoldenCase::query()
            ->when($this->option('only-reviewed'), fn ($q) => $q->where('reviewed', true))
            ->orderBy('case_id')
            ->get();
        if (($limit = (int) $this->option('limit')) > 0) {
            $cases = $cases->take($limit);
        }
        if ($cases->isEmpty()) {
            $this->error('No cases.');

            return self::FAILURE;
        }

        $this->line(sprintf('Summarizing + judging <info>%d</info> cases · %s/%s',
            $cases->count(), config('triage.provider'), config('triage.model')));

        $results = [];
        $faith = [];
        $complete = [];
        $promptTokens = 0;
        $completionTokens = 0;

        $bar = $this->output->createProgressBar($cases->count());
        $bar->start();
        foreach ($cases as $case) {
            $input = $this->evidence($case);
            try {
                $s = $summarizer->summarize($input);
                $j = $judge->judge($input, $s['summary']);
            } catch (\Throwable $e) {
                $bar->advance();

                continue;
            }
            $promptTokens += $s['prompt_tokens'] + $j['prompt_tokens'];
            $completionTokens += $s['completion_tokens'] + $j['completion_tokens'];
            $faith[] = $j['faithfulness'];
            $complete[] = $j['completeness'];
            $results[] = [
                'id' => $case->case_id,
                'summary' => $s['summary'],
                'faithfulness' => $j['faithfulness'],
                'completeness' => $j['completeness'],
                'note' => $j['note'],
            ];
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $n = count($results);
        $avg = fn (array $a) => $a === [] ? 0 : round(array_sum($a) / count($a), 2);
        $meanFaith = $avg($faith);
        $meanComplete = $avg($complete);
        $faithful = count(array_filter($faith, fn ($x) => $x >= 4));
        $completeHi = count(array_filter($complete, fn ($x) => $x >= 4));

        $report = [
            'timestamp' => Carbon::now()->toIso8601String(),
            'kind' => 'summary',
            'provider' => config('triage.provider'),
            'model' => config('triage.model'),
            'n' => $n,
            'note' => 'Judge and summarizer share a model — validate against human scores before trusting.',
            'mean_faithfulness' => $meanFaith,
            'mean_completeness' => $meanComplete,
            'pct_faithful_ge4' => $n ? round($faithful / $n * 100, 1) : 0,
            'pct_complete_ge4' => $n ? round($completeHi / $n * 100, 1) : 0,
            'tokens' => ['prompt' => $promptTokens, 'completion' => $completionTokens],
            'results' => $results,
        ];
        $dir = $this->option('out');
        $dir = str_starts_with($dir, DIRECTORY_SEPARATOR) ? $dir : base_path($dir);
        @mkdir($dir, 0755, true);
        $path = "{$dir}/summary-".Carbon::now()->format('Ymd-His').'.json';
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf('Mean faithfulness: %.2f/5   |   mean completeness: %.2f/5   (n=%d)', $meanFaith, $meanComplete, $n));
        $this->line(sprintf('Faithful (≥4): %.0f%%   Complete (≥4): %.0f%%', $report['pct_faithful_ge4'], $report['pct_complete_ge4']));
        $this->newLine();
        $this->line('<comment>Sample (spot-check the judge against your own scoring):</comment>');
        foreach (array_slice($results, 0, 5) as $r) {
            $this->line("  <info>{$r['id']}</info> f={$r['faithfulness']} c={$r['completeness']}: ".$r['summary']);
        }
        $this->info("Report → {$path}");
        $this->newLine();
        $this->warn('"Judge the judge": manually score ~15 of these and confirm the judge agrees before trusting the numbers.');

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function evidence(GoldenCase $case): array
    {
        return [
            'app' => $case->app,
            'error_type' => $case->error_type,
            'message' => $case->input['message'] ?? null,
            'root_exception' => $case->input['root_exception'] ?? null,
            'error_detail' => $case->input['error_detail'] ?? [],
        ];
    }
}
