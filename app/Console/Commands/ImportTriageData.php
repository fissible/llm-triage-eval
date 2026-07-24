<?php

namespace App\Console\Commands;

use App\Models\EvalRun;
use App\Models\GoldenCase;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ImportTriageData extends Command
{
    protected $signature = 'triage:import
        {--golden=database/golden/candidates.jsonl : Golden set JSONL to import}
        {--reports=storage/eval-reports : Directory of eval report JSONs to import}';

    protected $description = 'Import the golden set and eval reports from files into the database';

    public function handle(): int
    {
        $this->importGolden($this->option('golden'));
        $this->importReports($this->option('reports'));

        return self::SUCCESS;
    }

    private function importGolden(string $path): void
    {
        if (! is_file($path)) {
            $this->warn("Golden set not found: {$path} (skipping)");

            return;
        }

        $n = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (trim($line) === '' || ($c = json_decode($line, true)) === null) {
                continue;
            }
            $meta = $c['meta'] ?? [];
            $input = $c['input'] ?? [];
            GoldenCase::updateOrCreate(
                ['case_id' => $c['id']],
                [
                    'weak_label' => $c['weak_label'] ?? 'unknown',
                    'gold_label' => $c['gold_label'] ?? ($c['weak_label'] ?? 'unknown'),
                    'reviewed' => (bool) ($c['reviewed'] ?? false),
                    'note' => $c['note'] ?? null,
                    'app' => $meta['app'] ?? null,
                    'env' => $meta['env'] ?? null,
                    'error_type' => $meta['error_type'] ?? null,
                    'correlation_id' => $meta['correlation_id'] ?? null,
                    'message' => $input['message'] ?? null,
                    'source_file' => $meta['source_file'] ?? null,
                    'occurred_at' => isset($meta['timestamp']) ? Carbon::parse($meta['timestamp']) : null,
                    'input' => $input,
                ]
            );
            $n++;
        }
        $this->info("Golden cases imported/updated: {$n}");
    }

    private function importReports(string $dir): void
    {
        $files = glob(rtrim($dir, '/').'/*.json') ?: [];
        $imported = 0;
        foreach ($files as $file) {
            if (EvalRun::where('report_path', $file)->exists()) {
                continue; // idempotent
            }
            $r = json_decode((string) file_get_contents($file), true);
            if (! is_array($r) || ! isset($r['metrics'])) {
                continue;
            }
            $run = EvalRun::create([
                'report_path' => $file,
                'prompt_version' => $r['prompt_version'] ?? '?',
                'provider' => $r['provider'] ?? '?',
                'model' => $r['model'] ?? '?',
                'golden_set' => $r['golden_set'] ?? null,
                'n' => $r['n'] ?? 0,
                'llm_accuracy' => $r['metrics']['llm_accuracy'] ?? 0,
                'baseline_accuracy' => $r['metrics']['baseline_accuracy'] ?? 0,
                'prompt_tokens' => $r['tokens']['prompt'] ?? 0,
                'completion_tokens' => $r['tokens']['completion'] ?? 0,
                'cost_usd' => $r['cost_usd'] ?? 0,
                'fully_reviewed' => (bool) ($r['fully_reviewed'] ?? false),
                'per_category' => $r['metrics']['per_category'] ?? [],
                'confusion' => $r['metrics']['confusion'] ?? [],
                'ran_at' => isset($r['timestamp']) ? Carbon::parse($r['timestamp']) : Carbon::now(),
            ]);
            foreach ($r['results'] ?? [] as $res) {
                $run->results()->create([
                    'case_id' => $res['id'] ?? '?',
                    'gold' => $res['gold'] ?? '?',
                    'predicted' => $res['predicted'] ?? '?',
                    'baseline' => $res['baseline'] ?? '?',
                    'correct' => (bool) ($res['correct'] ?? false),
                    'rationale' => $res['rationale'] ?? null,
                ]);
            }
            $imported++;
        }
        $this->info("Eval reports imported: {$imported}");
    }
}
