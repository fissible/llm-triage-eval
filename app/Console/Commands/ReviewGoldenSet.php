<?php

namespace App\Console\Commands;

use App\Enums\FailureCategory;
use Illuminate\Console\Command;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ReviewGoldenSet extends Command
{
    protected $signature = 'triage:review
        {golden=database/golden/candidates.jsonl : Golden set to review (JSONL)}
        {--all : Include cases already marked reviewed}
        {--category= : Only review cases whose current label matches this category}
        {--report= : An eval report JSON — show the LLM guess/rationale as a hint}
        {--disagreements : Only review cases where the LLM disagreed with the label (needs --report)}
        {--dump : Non-interactive: print the (filtered) cases and exit}';

    protected $description = 'Interactively review/correct golden-set labels (writes back after each case; resumable)';

    public function handle(): int
    {
        $path = $this->argument('golden');
        if (! is_file($path)) {
            $this->error("Golden set not found: {$path}");

            return self::FAILURE;
        }

        $cases = $this->load($path);
        $hints = $this->loadHints($this->option('report'));

        // Which indexes need review, in file order.
        $queue = [];
        foreach ($cases as $i => $c) {
            $label = $c['gold_label'] ?? 'unknown';
            if (! $this->option('all') && ($c['reviewed'] ?? false) === true) {
                continue;
            }
            if (($cat = $this->option('category')) && $label !== $cat) {
                continue;
            }
            if ($this->option('disagreements')) {
                $pred = $hints[$c['id'] ?? '']['predicted'] ?? null;
                if ($pred === null || $pred === $label) {
                    continue;
                }
            }
            $queue[] = $i;
        }

        if ($queue === []) {
            $this->info('Nothing to review with those filters. 🎉  (Use --all to re-review.)');

            return self::SUCCESS;
        }

        if ($this->option('dump')) {
            foreach ($queue as $i) {
                $this->line($this->format($cases[$i], $hints));
                $this->line(str_repeat('─', 72));
            }
            $this->info(count($queue).' case(s) shown.');

            return self::SUCCESS;
        }

        $options = $this->categoryOptions();
        intro(sprintf('Reviewing %d case(s) in %s', count($queue), $path));

        $done = 0;
        foreach ($queue as $pos => $i) {
            $case = $cases[$i];
            note($this->format($case, $hints), 'Case '.($pos + 1).' of '.count($queue).'  ·  '.($case['id'] ?? '?'));

            $current = $case['gold_label'] ?? 'unknown';
            $choice = select(
                label: "Category for {$case['id']}?",
                options: $options + ['__skip__' => '⏭  skip (leave unreviewed)', '__quit__' => '⏹  save & quit'],
                default: $current,
                scroll: 15,
            );

            if ($choice === '__quit__') {
                break;
            }
            if ($choice === '__skip__') {
                continue;
            }

            $noteText = text(
                label: 'Note (optional — why, or what made it tricky)',
                default: (string) ($case['note'] ?? ''),
                required: false,
            );

            $cases[$i]['gold_label'] = $choice;
            $cases[$i]['note'] = $noteText;
            $cases[$i]['reviewed'] = true;
            $this->save($path, $cases); // write back after every case → resumable
            $done++;
        }

        $reviewed = count(array_filter($cases, fn ($c) => ($c['reviewed'] ?? false) === true));
        outro("Reviewed {$done} this session · {$reviewed}/".count($cases).' total marked reviewed.');

        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    private function load(string $path): array
    {
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (trim($line) !== '' && ($d = json_decode($line, true)) !== null) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /** @param  list<array<string,mixed>>  $cases */
    private function save(string $path, array $cases): void
    {
        $tmp = $path.'.tmp';
        $fh = fopen($tmp, 'w');
        foreach ($cases as $c) {
            fwrite($fh, json_encode($c, JSON_UNESCAPED_SLASHES)."\n");
        }
        fclose($fh);
        rename($tmp, $path); // atomic: never leave a half-written golden set
    }

    /** @return array<string,array{predicted:string,rationale:?string}> */
    private function loadHints(?string $reportPath): array
    {
        if (! $reportPath || ! is_file($reportPath)) {
            return [];
        }
        $report = json_decode((string) file_get_contents($reportPath), true);
        $hints = [];
        foreach ($report['results'] ?? [] as $r) {
            if (isset($r['id'])) {
                $hints[$r['id']] = ['predicted' => $r['predicted'] ?? null, 'rationale' => $r['rationale'] ?? null];
            }
        }

        return $hints;
    }

    /** @return array<string,string> */
    private function categoryOptions(): array
    {
        $opts = [];
        foreach (FailureCategory::cases() as $c) {
            $opts[$c->value] = "{$c->value} — {$c->label()}";
        }

        return $opts;
    }

    /**
     * @param  array<string,mixed>  $case
     * @param  array<string,array{predicted:string,rationale:?string}>  $hints
     */
    private function format(array $case, array $hints): string
    {
        $in = $case['input'] ?? [];
        $meta = $case['meta'] ?? [];
        $lines = [];
        $add = function (string $k, $v) use (&$lines) {
            if ($v !== null && $v !== '' && $v !== []) {
                $lines[] = sprintf('  %-14s %s', $k.':', is_array($v) ? implode(' / ', $v) : $v);
            }
        };

        $add('message', $in['message'] ?? null);
        $add('root', $in['root_exception'] ?? null);
        $add('element', $in['element'] ?? null);
        if (! empty($in['http_method'])) {
            $add('http', trim(($in['http_method'] ?? '').' '.($in['http_status'] ?? '').' '.($in['resource_url'] ?? '')));
        }
        if (! empty($in['stack_top'])) {
            $add('stack', array_slice($in['stack_top'], 0, 2));
        }
        $lines[] = '';
        $add('error_type', $meta['error_type'] ?? null);
        $add('app / env', trim(($meta['app'] ?? '?').' / '.($meta['env'] ?? '?')));
        // Identifiers for cross-referencing in Anypoint Runtime Manager:
        $add('correlation', $meta['correlation_id'] ?? null);
        $add('time', $meta['timestamp'] ?? null);
        $add('log file', $meta['source_file'] ?? null);
        $add('weak label', $case['weak_label'] ?? null);
        $add('current', $case['gold_label'] ?? null);

        if (isset($hints[$case['id'] ?? ''])) {
            $h = $hints[$case['id']];
            $flag = ($h['predicted'] ?? null) !== ($case['gold_label'] ?? null) ? '  ⚠ disagrees' : '';
            $add('LLM guess', ($h['predicted'] ?? '?').$flag);
            $add('LLM why', $h['rationale'] ?? null);
        }
        if (! empty($case['note'])) {
            $add('note', $case['note']);
        }

        return implode("\n", $lines);
    }
}
