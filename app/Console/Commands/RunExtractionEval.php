<?php

namespace App\Console\Commands;

use App\Models\GoldenCase;
use App\Services\Triage\FailureExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunExtractionEval extends Command
{
    protected $signature = 'triage:eval-extract
        {--prompt=v1 : Extraction prompt version}
        {--only-reviewed : Only cases with reviewed=true}
        {--limit=0 : Cap number of cases (0 = all)}
        {--out=storage/eval-reports : Report directory}';

    protected $description = 'Score LLM field extraction per-field against the parser/rule bootstrap gold';

    public function handle(FailureExtractor $extractor): int
    {
        $version = $this->option('prompt');
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

        $this->line(sprintf('Extracting from <info>%d</info> cases · prompt <info>%s</info> · %s/%s',
            $cases->count(), $version, config('triage.provider'), config('triage.model')));

        $fields = FailureExtractor::FIELDS;
        $hits = array_fill_keys($fields, 0);
        $divergences = array_fill_keys($fields, []);
        $results = [];
        $promptTokens = 0;
        $completionTokens = 0;

        $bar = $this->output->createProgressBar($cases->count());
        $bar->start();
        foreach ($cases as $case) {
            $gold = $this->goldFor($case);
            try {
                $pred = $extractor->extract($case->input ?? [], $version);
            } catch (\Throwable $e) {
                $pred = array_fill_keys($fields, null) + ['operation' => 'none', 'prompt_tokens' => 0, 'completion_tokens' => 0];
            }
            $promptTokens += $pred['prompt_tokens'];
            $completionTokens += $pred['completion_tokens'];

            $row = ['id' => $case->case_id, 'gold' => $gold, 'predicted' => []];
            foreach ($fields as $f) {
                $ok = $this->matches($f, $gold[$f], $pred[$f] ?? null);
                $hits[$f] += $ok ? 1 : 0;
                $row['predicted'][$f] = $pred[$f] ?? null;
                if (! $ok) {
                    $divergences[$f][] = "{$case->case_id}: gold=".$this->fmt($gold[$f])." llm=".$this->fmt($pred[$f] ?? null);
                }
            }
            $results[] = $row;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $n = $cases->count();
        $perField = [];
        foreach ($fields as $f) {
            $perField[$f] = round($hits[$f] / $n, 4);
        }

        $report = [
            'timestamp' => Carbon::now()->toIso8601String(),
            'kind' => 'extraction',
            'prompt_version' => $version,
            'provider' => config('triage.provider'),
            'model' => config('triage.model'),
            'n' => $n,
            'gold_source' => 'parser/rule bootstrap (review divergences to make it a fair LLM-vs-regex test)',
            'per_field_llm_accuracy' => $perField,
            'divergences' => $divergences,
            'tokens' => ['prompt' => $promptTokens, 'completion' => $completionTokens],
            'results' => $results,
        ];
        $dir = $this->option('out');
        $dir = str_starts_with($dir, DIRECTORY_SEPARATOR) ? $dir : base_path($dir);
        @mkdir($dir, 0755, true);
        $path = "{$dir}/extract-".Carbon::now()->format('Ymd-His')."-{$version}.json";
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->table(
            ['Field', 'LLM agrees w/ parser gold', 'Divergences'],
            collect($fields)->map(fn ($f) => [$f, $this->pct($perField[$f]), count($divergences[$f])])->all()
        );
        $this->line("tokens: {$promptTokens} in / {$completionTokens} out");
        $this->info("Report → {$path}");
        $this->newLine();
        $this->warn('Gold is the parser/rule bootstrap → this measures LLM↔parser agreement. Review the divergences to turn it into a true LLM-vs-regex comparison.');

        return self::SUCCESS;
    }

    /**
     * Details-aware gold: for wrapper/cascade cases the root call's fields live in
     * the correlated details, not the shallow top-level message — so scan the details
     * first (then fall back to the top-level parser fields).
     *
     * @return array<string,mixed>
     */
    private function goldFor(GoldenCase $case): array
    {
        $in = $case->input ?? [];
        $details = $in['error_detail'] ?? [];
        $detailText = implode(' ', array_map(
            fn ($d) => is_array($d) ? (($d['description'] ?? '').' '.($d['message'] ?? '')) : '',
            $details
        ));
        $text = $detailText.' '.($in['message'] ?? '');

        $method = $this->rx('/HTTP (GET|POST|PUT|PATCH|DELETE)/i', $text) ?? ($in['http_method'] ?? null);
        $status = $this->rx('/(?:status code |\()(\d{3})\)?/', $text) ?? (isset($in['http_status']) ? (string) $in['http_status'] : null);
        $entity = $this->rx('#/v1/api/([A-Za-z-]+)#', $text) ?? ($in['target_entity'] ?? null);
        $constraint = $this->rx('/violates .*constraint "([^"]+)"/', $detailText);
        $errorType = $this->deepestCode($details) ?: $case->error_type;

        return [
            'http_method' => $method ? strtoupper($method) : null,
            'http_status' => $status,
            'target_entity' => $entity,
            'error_type' => $errorType,
            'operation' => $this->deriveOperation($errorType, $method),
            'constraint' => $constraint,
        ];
    }

    /** The most-specific (root) detail code: a constraint's code, else a non-wrapper code. */
    private function deepestCode(array $details): ?string
    {
        $wrappers = ['MULE:COMPOSITE_ROUTING', 'COMPOSITE_ROUTING', 'MULE:UNKNOWN'];
        $best = null;
        foreach ($details as $d) {
            if (! is_array($d) || empty($d['code'])) {
                continue;
            }
            $code = (string) $d['code'];
            if (preg_match('/violates .*constraint|duplicate key/i', (string) ($d['description'] ?? ''))) {
                return $code;
            }
            if (! in_array($code, $wrappers, true) && ! str_ends_with($code, ':INTERNAL_SERVER_ERROR')) {
                $best = $code;
            }
        }

        return $best;
    }

    private function rx(string $pattern, string $subject): ?string
    {
        return ($subject !== '' && preg_match($pattern, $subject, $m)) ? $m[1] : null;
    }

    private function deriveOperation(?string $errorType, ?string $method): string
    {
        $t = strtoupper((string) $errorType);
        foreach (['INSERT' => 'insert', 'UPDATE' => 'update', 'DELETE' => 'delete', 'SELECT' => 'get', 'PATCH' => 'patch'] as $k => $v) {
            if (str_contains($t, $k)) {
                return $v;
            }
        }

        return match (strtoupper((string) $method)) {
            'GET' => 'get', 'POST' => 'post', 'PUT' => 'update', 'PATCH' => 'patch', 'DELETE' => 'delete',
            default => 'none',
        };
    }

    private function matches(string $field, mixed $gold, mixed $pred): bool
    {
        $norm = fn ($v) => $v === null ? null : mb_strtolower(trim((string) $v));
        // error_type is a case-sensitive code; compare trimmed but not lowercased.
        if ($field === 'error_type') {
            $norm = fn ($v) => $v === null ? null : trim((string) $v);
        }

        return $norm($gold) === $norm($pred);
    }

    private function fmt(mixed $v): string
    {
        return $v === null ? 'null' : (string) $v;
    }

    private function pct(float $v): string
    {
        return number_format($v * 100, 1).'%';
    }
}
