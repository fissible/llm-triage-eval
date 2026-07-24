<?php

namespace App\Console\Commands;

use App\Models\GoldenCase;
use App\Services\Triage\Sanitizer;
use App\Services\Triage\TaxonomyClassifier;
use Illuminate\Console\Command;

class EnrichDetails extends Command
{
    protected $signature = 'triage:enrich-details
        {pools* : Parsed pool JSONL files (must contain error_detail)}
        {--golden=database/golden/candidates.jsonl : Golden set JSONL to enrich}';

    protected $description = 'Backfill correlated error_detail onto existing golden cases (by correlation id), preserving labels';

    public function handle(Sanitizer $sanitizer, TaxonomyClassifier $classifier): int
    {
        // Build correlation_id => sanitized error_detail from the pools.
        $map = [];
        foreach ($this->argument('pools') as $pool) {
            if (! is_file($pool)) {
                $this->warn("skip (not found): {$pool}");

                continue;
            }
            $fh = fopen($pool, 'r');
            while (($line = fgets($fh)) !== false) {
                if (trim($line) === '' || ($c = json_decode($line, true)) === null) {
                    continue;
                }
                $cid = $c['correlation_id'] ?? null;
                $details = $c['error_detail'] ?? null;
                if ($cid && is_array($details) && $details !== []) {
                    // list of {code, message, description} — sanitize each field.
                    $map[$cid] = array_map(
                        fn ($d) => is_array($d)
                            ? array_map(fn ($v) => is_string($v) ? $sanitizer->sanitizeString($v) : $v, $d)
                            : $d,
                        $details
                    );
                }
            }
            fclose($fh);
        }
        $this->info('Correlation ids with detail: '.count($map));

        // 1) Enrich the golden JSONL file (preserve labels/review/notes).
        $golden = $this->option('golden');
        $fileHits = 0;
        if (is_file($golden)) {
            $lines = [];
            foreach (file($golden, FILE_IGNORE_NEW_LINES) as $line) {
                if (trim($line) === '' || ($c = json_decode($line, true)) === null) {
                    continue;
                }
                $cid = $c['meta']['correlation_id'] ?? null;
                if ($cid && isset($map[$cid])) {
                    $c['input']['error_detail'] = $map[$cid];
                    // Recompute the rule baseline now that the root cause is visible.
                    $c['weak_label'] = $classifier->classify(
                        array_merge($c['input'], ['error_type' => $c['meta']['error_type'] ?? null])
                    )->value;
                    $fileHits++;
                }
                $lines[] = json_encode($c, JSON_UNESCAPED_SLASHES);
            }
            file_put_contents($golden, implode("\n", $lines)."\n");
        }
        $this->info("Golden file cases enriched: {$fileHits}");

        // 2) Enrich the DB golden_cases (input only; labels untouched).
        $dbHits = 0;
        foreach (GoldenCase::all() as $case) {
            if ($case->correlation_id && isset($map[$case->correlation_id])) {
                $input = $case->input;
                $input['error_detail'] = $map[$case->correlation_id];
                $case->input = $input;
                $case->weak_label = $classifier->classify(
                    array_merge($input, ['error_type' => $case->error_type])
                )->value;
                $case->save();
                $dbHits++;
            }
        }
        $this->info("DB golden cases enriched: {$dbHits}");

        return self::SUCCESS;
    }
}
