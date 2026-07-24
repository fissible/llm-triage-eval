<?php

namespace App\Console\Commands;

use App\Services\Triage\MuleLogParser;
use App\Services\Triage\TaxonomyClassifier;
use Illuminate\Console\Command;

class ParseMuleLog extends Command
{
    protected $signature = 'triage:parse
        {path* : One or more log files (globs allowed)}
        {--out=storage/app/triage/cases.jsonl : Output JSONL path}
        {--env= : Override deployment env tag (prod/uat/…); default = inferred from filename}
        {--limit=0 : Max cases to write (0 = all)}';

    protected $description = 'Parse CloudHub/Mule logs into weak-labeled error-case records (JSONL)';

    public function handle(MuleLogParser $parser, TaxonomyClassifier $classifier): int
    {
        $paths = $this->resolvePaths($this->argument('path'));
        if ($paths === []) {
            $this->error('No log files matched.');

            return self::FAILURE;
        }

        $out = $this->option('out');
        $out = str_starts_with($out, DIRECTORY_SEPARATOR) ? $out : base_path($out);
        $limit = (int) $this->option('limit');
        @mkdir(dirname($out), 0755, true);
        $fh = fopen($out, 'w');

        $total = 0;
        $byCategory = [];
        $byType = [];

        foreach ($paths as $path) {
            $this->line("Parsing <info>{$path}</info> …");
            foreach ($parser->parseFile($path, $this->option('env') ?: null) as $case) {
                $category = $classifier->classify($case);
                $case['weak_label'] = $category->value;

                fwrite($fh, json_encode($case, JSON_UNESCAPED_SLASHES)."\n");

                $total++;
                $byCategory[$category->value] = ($byCategory[$category->value] ?? 0) + 1;
                $key = $case['error_type'] ?? '(none)';
                $byType[$key] = ($byType[$key] ?? 0) + 1;

                if ($limit > 0 && $total >= $limit) {
                    break 2;
                }
            }
        }

        fclose($fh);

        $this->newLine();
        $this->info("Parsed {$total} error cases → {$out}");

        arsort($byCategory);
        $this->newLine();
        $this->line('<comment>Weak-label taxonomy distribution:</comment>');
        $this->table(
            ['Category', 'Count'],
            collect($byCategory)->map(fn ($c, $k) => [$k, $c])->values()->all()
        );

        arsort($byType);
        $this->line('<comment>Raw Error type distribution (top 15):</comment>');
        $this->table(
            ['Error type', 'Count'],
            collect($byType)->take(15)->map(fn ($c, $k) => [$k, $c])->values()->all()
        );

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private function resolvePaths(array $patterns): array
    {
        $files = [];
        foreach ($patterns as $pattern) {
            $pattern = str_replace('~', getenv('HOME') ?: '', $pattern);
            $matched = glob($pattern) ?: [];
            foreach ($matched as $m) {
                if (is_file($m)) {
                    $files[$m] = true;
                }
            }
        }

        return array_keys($files);
    }
}
