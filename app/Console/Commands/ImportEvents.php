<?php

namespace App\Console\Commands;

use App\Models\LogEvent;
use App\Services\Triage\Sanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImportEvents extends Command
{
    protected $signature = 'triage:import-events
        {pools* : Parsed pool JSONL files (from triage:parse)}
        {--fresh : Truncate log_events before importing}';

    protected $description = 'Load parsed events into the log_events table for correlation/incident tracing';

    public function handle(Sanitizer $sanitizer): int
    {
        if ($this->option('fresh')) {
            LogEvent::truncate();
            $this->line('Truncated log_events.');
        }

        $total = 0;
        foreach ($this->argument('pools') as $pool) {
            if (! is_file($pool)) {
                $this->warn("skip (not found): {$pool}");

                continue;
            }
            $batch = [];
            $fh = fopen($pool, 'r');
            while (($line = fgets($fh)) !== false) {
                if (trim($line) === '' || ($c = json_decode($line, true)) === null) {
                    continue;
                }
                $c = $sanitizer->sanitizeCase($c);
                $batch[] = [
                    'correlation_id' => $c['correlation_id'] ?? null,
                    'app' => $c['app'] ?? null,
                    'env' => $c['env'] ?? null,
                    'error_type' => $c['error_type'] ?? null,
                    'weak_label' => $c['weak_label'] ?? null,
                    'occurred_at' => isset($c['timestamp']) ? Carbon::parse($c['timestamp']) : null,
                    'message' => $c['message'] ?? null,
                    'root_exception' => $c['root_exception'] ?? null,
                    'resource_url' => $c['resource_url'] ?? null,
                    'http_status' => $c['http_status'] ?? null,
                    'error_detail' => isset($c['error_detail']) ? json_encode($c['error_detail']) : null,
                    'source_file' => $c['source_file'] ?? null,
                ];
                if (count($batch) >= 500) {
                    DB::table('log_events')->insert($batch);
                    $total += count($batch);
                    $batch = [];
                }
            }
            fclose($fh);
            if ($batch !== []) {
                DB::table('log_events')->insert($batch);
                $total += count($batch);
            }
        }

        $this->info("Imported {$total} events. Distinct correlations: ".LogEvent::distinct('correlation_id')->count('correlation_id'));

        return self::SUCCESS;
    }
}
