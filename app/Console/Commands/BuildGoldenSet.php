<?php

namespace App\Console\Commands;

use App\Services\Triage\Sanitizer;
use Illuminate\Console\Command;

class BuildGoldenSet extends Command
{
    protected $signature = 'triage:golden
        {pool : Parsed pool JSONL (from triage:parse)}
        {--out=database/golden/candidates.jsonl : Output candidate golden set}
        {--size=80 : Target number of cases}';

    protected $description = 'Dedup, sanitize and stratified-sample a candidate golden set for human review';

    public function handle(Sanitizer $sanitizer): int
    {
        $poolPath = $this->argument('pool');
        if (! is_file($poolPath)) {
            $this->error("Pool file not found: {$poolPath}");

            return self::FAILURE;
        }

        // --- Stream + dedup by error signature (collapse per-record variants) ---
        // Streamed (not file()) because the pool is 60MB+; the heavy `raw` field is
        // dropped on ingest — the golden set uses the curated `input`, not `raw`.
        $bySig = [];
        $fh = fopen($poolPath, 'r');
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $case = json_decode($line, true);
            if (! is_array($case)) {
                continue;
            }
            unset($case['raw']);
            $bySig[$this->signature($case)] ??= $case;
        }
        fclose($fh);
        $unique = array_values($bySig);
        $this->line('Unique error signatures: <info>'.count($unique).'</info>');

        // --- Group by weak label ---
        $groups = [];
        foreach ($unique as $case) {
            $groups[$case['weak_label'] ?? 'unknown'][] = $case;
        }
        ksort($groups);

        // --- Even per-category sampling, then round-robin top-up to target ---
        $size = (int) $this->option('size');
        $perCat = max(1, intdiv($size, max(1, count($groups))));
        $selected = [];
        foreach ($groups as $cases) {
            $selected = array_merge($selected, array_slice($cases, 0, $perCat));
        }
        // top-up from the remainder of the largest categories until we hit size
        if (count($selected) < $size) {
            $pointers = [];
            foreach ($groups as $cat => $cases) {
                $pointers[$cat] = $perCat;
            }
            $progress = true;
            while (count($selected) < $size && $progress) {
                $progress = false;
                foreach ($groups as $cat => $cases) {
                    if (($cases[$pointers[$cat]] ?? null) !== null) {
                        $selected[] = $cases[$pointers[$cat]++];
                        $progress = true;
                        if (count($selected) >= $size) {
                            break;
                        }
                    }
                }
            }
        }

        // --- Sanitize + shape for review ---
        $out = $this->option('out');
        $out = str_starts_with($out, DIRECTORY_SEPARATOR) ? $out : base_path($out);
        @mkdir(dirname($out), 0755, true);
        $fh = fopen($out, 'w');
        $dist = [];
        $i = 0;
        foreach ($selected as $case) {
            $case = $sanitizer->sanitizeCase($case);
            $weak = $case['weak_label'] ?? 'unknown';
            $dist[$weak] = ($dist[$weak] ?? 0) + 1;

            $record = [
                'id' => sprintf('gs-%04d', ++$i),
                'gold_label' => $weak,   // <-- REVIEW & CORRECT THIS
                'weak_label' => $weak,   // auto baseline (reference; leave as-is)
                'reviewed' => false,
                'note' => '',
                'input' => [
                    'message' => $case['message'] ?? null,
                    'element' => $case['element'] ?? null,
                    'root_exception' => $case['root_exception'] ?? null,
                    'stack_top' => $case['stack_top'] ?? [],
                    'http_method' => $case['http_method'] ?? null,
                    'http_status' => $case['http_status'] ?? null,
                    'resource_url' => $case['resource_url'] ?? null,
                    'target_entity' => $case['target_entity'] ?? null,
                ],
                // error_type kept OUT of input on purpose — it would trivialize
                // classification and won't exist in the target system's logs.
                'meta' => [
                    'app' => $case['app'] ?? null,
                    'env' => $case['env'] ?? null,
                    'error_type' => $case['error_type'] ?? null,
                    'correlation_id' => $case['correlation_id'] ?? null,
                    'timestamp' => $case['timestamp'] ?? null,
                    'source_file' => $case['source_file'] ?? null,
                ],
            ];
            fwrite($fh, json_encode($record, JSON_UNESCAPED_SLASHES)."\n");
        }
        fclose($fh);

        $this->newLine();
        $this->info('Wrote '.count($selected)." candidate cases → {$out}");
        arsort($dist);
        $this->table(['Category', 'Count'], collect($dist)->map(fn ($c, $k) => [$k, $c])->values()->all());
        $this->newLine();
        $this->line('<comment>Next:</comment> review each line, correct <info>gold_label</info> where the weak label is wrong, set <info>reviewed</info> to true, add a <info>note</info> for tricky cases.');

        return self::SUCCESS;
    }

    /** Collapse per-record variants: same shape of failure → one signature. */
    private function signature(array $case): string
    {
        $msg = (string) ($case['message'] ?? '');
        $root = (string) ($case['root_exception'] ?? '');
        // normalize volatile bits (ids, numbers, hex) so variants collapse
        $norm = preg_replace(['/[0-9a-f]{6,}/i', '/\d+/'], ['#', '#'], $msg.'|'.$root);

        return md5(($case['error_type'] ?? '').'|'.($case['element'] ?? '').'|'.$norm);
    }
}
