<?php

namespace App\Console\Commands;

use App\Models\GoldenCase;
use App\Services\Triage\FailureClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunEvals extends Command
{
    protected $signature = 'triage:eval
        {--source=db : Where to load the golden set: db (your reviewed labels) or file}
        {--golden=database/golden/candidates.jsonl : Golden JSONL (only when --source=file)}
        {--prompt= : Prompt version (default: config triage.prompt_version)}
        {--only-reviewed : Only evaluate cases with reviewed=true}
        {--limit=0 : Cap number of cases (0 = all; use for a quick smoke test)}
        {--out=storage/eval-reports : Directory for the timestamped JSON report}';

    protected $description = 'Run the golden set through the LLM classifier and write a scored, timestamped report';

    public function handle(FailureClassifier $classifier): int
    {
        $version = $this->option('prompt') ?: config('triage.prompt_version');

        if ($this->option('source') === 'file') {
            $goldenPath = $this->option('golden');
            if (! is_file($goldenPath)) {
                $this->error("Golden set not found: {$goldenPath}");

                return self::FAILURE;
            }
            $cases = $this->loadCases($goldenPath);
            $sourceLabel = $goldenPath;
        } else {
            $cases = $this->loadFromDb();
            $sourceLabel = 'db:golden_cases';
        }

        if ($this->option('only-reviewed')) {
            $cases = array_values(array_filter($cases, fn ($c) => ($c['reviewed'] ?? false) === true));
        }
        if (($limit = (int) $this->option('limit')) > 0) {
            $cases = array_slice($cases, 0, $limit);
        }
        if ($cases === []) {
            $this->error('No cases to evaluate (did you pass --only-reviewed on an unreviewed set?).');

            return self::FAILURE;
        }

        $this->line(sprintf('Evaluating <info>%d</info> cases · prompt <info>%s</info> · %s/%s',
            count($cases), $version, config('triage.provider'), config('triage.model')));

        $results = [];
        $confusion = [];        // [gold][pred] => count
        $llmCorrect = 0;
        $baselineCorrect = 0;
        $promptTokens = 0;
        $completionTokens = 0;

        $bar = $this->output->createProgressBar(count($cases));
        $bar->start();
        foreach ($cases as $case) {
            $gold = $case['gold_label'] ?? 'unknown';
            $baseline = $case['weak_label'] ?? 'unknown';

            try {
                $pred = $classifier->classify($case['input'] ?? [], $version);
            } catch (\Throwable $e) {
                $pred = ['category' => 'ERROR', 'rationale' => $e->getMessage(), 'prompt_tokens' => 0, 'completion_tokens' => 0];
            }

            $promptTokens += $pred['prompt_tokens'];
            $completionTokens += $pred['completion_tokens'];
            $confusion[$gold][$pred['category']] = ($confusion[$gold][$pred['category']] ?? 0) + 1;

            $llmHit = $pred['category'] === $gold;
            $baseHit = $baseline === $gold;
            $llmCorrect += $llmHit ? 1 : 0;
            $baselineCorrect += $baseHit ? 1 : 0;

            $results[] = [
                'id' => $case['id'] ?? null,
                'gold' => $gold,
                'predicted' => $pred['category'],
                'baseline' => $baseline,
                'correct' => $llmHit,
                'rationale' => $pred['rationale'],
                'input' => $case['input'] ?? [],
                'meta' => $case['meta'] ?? [],
            ];
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $n = count($cases);
        $llmAcc = $llmCorrect / $n;
        $baselineAcc = $baselineCorrect / $n;
        $perCategory = $this->perCategoryMetrics($confusion);
        $cost = ($promptTokens / 1e6) * config('triage.cost_per_mtok.input')
            + ($completionTokens / 1e6) * config('triage.cost_per_mtok.output');

        // ---- Report ----
        $stamp = Carbon::now()->format('Ymd-His');
        $reviewed = collect($cases)->every(fn ($c) => ($c['reviewed'] ?? false) === true);
        $report = [
            'timestamp' => Carbon::now()->toIso8601String(),
            'prompt_version' => $version,
            'provider' => config('triage.provider'),
            'model' => config('triage.model'),
            'golden_set' => $sourceLabel,
            'fully_reviewed' => $reviewed,
            'n' => $n,
            'metrics' => [
                'llm_accuracy' => round($llmAcc, 4),
                'baseline_accuracy' => round($baselineAcc, 4),
                'per_category' => $perCategory,
                'confusion' => $confusion,
            ],
            'tokens' => ['prompt' => $promptTokens, 'completion' => $completionTokens],
            'cost_usd' => round($cost, 4),
            'failures' => array_values(array_filter($results, fn ($r) => ! $r['correct'])),
            'results' => $results,
        ];
        $dir = $this->option('out');
        $dir = str_starts_with($dir, DIRECTORY_SEPARATOR) ? $dir : base_path($dir);
        @mkdir($dir, 0755, true);
        $path = "{$dir}/eval-{$stamp}-{$version}.json";
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // ---- Console summary ----
        if (! $reviewed) {
            $this->warn('NOTE: set is not fully reviewed — accuracy is vs. weak labels (a plumbing/agreement check, not ground truth).');
        }
        $this->info(sprintf('LLM accuracy: %.1f%%   |   rule-based baseline: %.1f%%   (%d cases)',
            $llmAcc * 100, $baselineAcc * 100, $n));
        $this->newLine();
        $this->table(
            ['Category', 'Support', 'Precision', 'Recall'],
            collect($perCategory)->map(fn ($m, $k) => [
                $k, $m['support'], $this->pct($m['precision']), $this->pct($m['recall']),
            ])->values()->all()
        );
        $failCount = count($report['failures']);
        $this->line("Misclassified: <comment>{$failCount}</comment> · tokens: {$promptTokens} in / {$completionTokens} out · cost: \$".number_format($cost, 4));
        $this->info("Report → {$path}");

        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    /**
     * Load the golden set from the database — your in-browser reviews are the
     * source of truth here, so accuracy reflects real ground truth.
     *
     * @return list<array<string,mixed>>
     */
    private function loadFromDb(): array
    {
        return GoldenCase::query()->orderBy('case_id')->get()->map(fn (GoldenCase $g) => [
            'id' => $g->case_id,
            'gold_label' => $g->gold_label,
            'weak_label' => $g->weak_label,
            'reviewed' => (bool) $g->reviewed,
            'input' => $g->input,
            'meta' => [
                'app' => $g->app,
                'env' => $g->env,
                'error_type' => $g->error_type,
                'correlation_id' => $g->correlation_id,
                'timestamp' => $g->occurred_at?->toDateTimeString(),
                'source_file' => $g->source_file,
            ],
        ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function loadCases(string $path): array
    {
        $cases = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $cases[] = $decoded;
            }
        }

        return $cases;
    }

    /**
     * Precision/recall/support per gold category from the confusion matrix.
     *
     * @param  array<string,array<string,int>>  $confusion
     * @return array<string,array{support:int,precision:float,recall:float}>
     */
    private function perCategoryMetrics(array $confusion): array
    {
        $labels = [];
        foreach ($confusion as $gold => $preds) {
            $labels[$gold] = true;
            foreach ($preds as $pred => $_) {
                $labels[$pred] = true;
            }
        }

        $out = [];
        foreach (array_keys($labels) as $label) {
            $tp = $confusion[$label][$label] ?? 0;
            $fn = array_sum($confusion[$label] ?? []) - $tp;                 // gold=label, pred≠label
            $predictedAs = 0;
            foreach ($confusion as $preds) {
                $predictedAs += $preds[$label] ?? 0;
            }
            $fp = $predictedAs - $tp;                                        // pred=label, gold≠label
            $support = $tp + $fn;
            $out[$label] = [
                'support' => $support,
                'precision' => ($tp + $fp) > 0 ? round($tp / ($tp + $fp), 4) : 0.0,
                'recall' => $support > 0 ? round($tp / $support, 4) : 0.0,
            ];
        }
        ksort($out);

        return $out;
    }

    private function pct(float $v): string
    {
        return number_format($v * 100, 1).'%';
    }
}
