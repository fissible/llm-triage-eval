<?php

namespace App\Services\Triage;

use Generator;

/**
 * Parses raw CloudHub / Mule log files into discrete error-case records.
 *
 * Grammar confirmed against real prod logs (crm-events-papi et al.):
 *
 *   Header line (starts a log event):
 *     2026-04-25 13:22:38.826 ERROR   <logger> [<thread…[app]…>]: event:<uuid>
 *
 *   Error block (continuation lines emitted by DefaultExceptionListener):
 *     Message               : <one line>
 *     Element               : <flow @ file.xml:line>
 *     Element DSL           : <xml>
 *     Error type            : <NAMESPACE:IDENTIFIER>   <- weak classification label
 *     FlowStack             : at … (multi-line)
 *     Payload               : <…>
 *     ----------------------------------------
 *     Root Exception stack trace:
 *     <exception message>
 *         at <frame>
 *
 * Streams line-by-line so 100 MB+ logs parse in constant memory.
 */
class MuleLogParser
{
    private const HEADER = '/^(?<ts>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}) (?<level>[A-Z]+)\s+(?<logger>\S+) (?<rest>.*)$/';

    /** A field-label line like "Error type            : FOO". */
    private const FIELD = '/^(?<label>[A-Z][A-Za-z ]+?) {2,}: (?<value>.*)$/';

    /**
     * Yield one associative array per ERROR event that carries a Mule error block.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function parseFile(string $path, ?string $env = null): Generator
    {
        $source = basename($path);
        $resolvedEnv = $this->detectEnv($source, $env);

        // Pass 1: collect the correlated JSON detail blocks (often logged at WARN)
        // keyed by correlation id — they carry the true root cause behind a generic
        // ERROR (e.g. the "601 write failed" ERROR vs. the "not-null constraint"
        // detail on the same transaction).
        $details = $this->scanDetails($path);

        // Pass 2: build ERROR cases and attach the matching detail.
        foreach ($this->events($path) as [$header, $body]) {
            $case = $this->buildCase($header, $body, $source, $resolvedEnv);
            if ($case === null) {
                continue;
            }
            $cid = $case['correlation_id'] ?? null;
            if ($cid !== null && isset($details[$cid])) {
                $case['error_detail'] = $details[$cid];
            }
            yield $case;
        }
    }

    /**
     * Yield [headerMatch, bodyLines] for every log event in the file (any level).
     *
     * @return Generator<int, array{0: array<string,string>, 1: list<string>}>
     */
    private function events(string $path): Generator
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open log file: {$path}");
        }

        $header = null;
        $body = [];
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if (preg_match(self::HEADER, $line, $m)) {
                    if ($header !== null) {
                        yield [$header, $body];
                    }
                    $header = $m;
                    $body = [];

                    continue;
                }
                if ($header !== null) {
                    $body[] = $line;
                }
            }
            if ($header !== null) {
                yield [$header, $body];
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Build correlation_id => list of {code, message, description} JSON detail
     * blocks. A single transaction commonly logs several (e.g. a COMPOSITE_ROUTING
     * wrapper AND the underlying constraint violation), so we keep all of them —
     * the root-cause one is what makes the case classifiable.
     *
     * @return array<string, list<array<string,string>>>
     */
    private function scanDetails(string $path): array
    {
        $map = [];   // cid => [sig => detail]  (sig-keyed for dedup)
        foreach ($this->events($path) as [$header, $body]) {
            $cid = $this->matchFirst('/event:([0-9a-fA-F][0-9a-fA-F-]{7,})/', $header['rest']);
            if ($cid === null) {
                continue;
            }
            // The message may begin on the header line (after "event:<cid> ") and
            // continue across body lines — stitch both together.
            $tail = $this->matchFirst('/event:[0-9a-fA-F-]{8,}\s(.*)$/', $header['rest']) ?? '';
            $text = trim($tail."\n".implode("\n", $body));
            if (! str_starts_with($text, '{')) {
                continue;
            }
            $json = $this->tryJson($text);
            if (! is_array($json) || ! (isset($json['code']) || isset($json['description']) || isset($json['transactionId']))) {
                continue;
            }
            $detail = array_filter([
                'code' => $json['code'] ?? null,
                'message' => $json['message'] ?? null,
                'description' => $json['description'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            if ($detail === []) {
                continue;
            }
            $map[$cid] ??= [];
            if (count($map[$cid]) < 12) {                    // cap per transaction
                $map[$cid][md5(json_encode($detail))] = $detail; // dedup identical
            }
        }

        return array_map('array_values', $map);
    }

    /** Decode JSON, tolerating trailing content by clipping to the outermost braces. */
    private function tryJson(string $text): ?array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Build an error-case record, or null if this event has no Mule error block.
     *
     * @param  array<string,string>  $header
     * @param  list<string>  $body
     * @return array<string,mixed>|null
     */
    private function buildCase(array $header, array $body, string $source, string $env): ?array
    {
        if ($header['level'] !== 'ERROR') {
            return null;
        }

        $bodyText = implode("\n", $body);

        // Only keep events that actually carry a parsed error block.
        if (! str_contains($bodyText, 'Error type') && ! str_contains($bodyText, 'Message ')) {
            return null;
        }

        $fields = $this->extractFields($body);
        if (! isset($fields['Error type']) && ! isset($fields['Message'])) {
            return null;
        }

        $message = $fields['Message'] ?? '';
        $errorType = $fields['Error type'] ?? null;
        $element = $fields['Element'] ?? null;
        [$rootMessage, $stackTop] = $this->extractRootException($body);

        // App name: the header thread is unreliable (Grizzly/idle threads carry no
        // app name), but the flow reference in Element/FlowStack always prefixes it,
        // e.g. "@ crm-events-papi:implementation/flows/…".
        $app = $this->matchFirst('/\[([a-z0-9][a-z0-9-]+)\]\.uber@/', $header['rest'])
            ?? $this->matchFirst('/@ ([a-z][a-z0-9-]+):/', $element ?? '')
            ?? $this->matchFirst('/@ ([a-z][a-z0-9-]+):/', $bodyText);

        return [
            'source_file' => $source,
            'env' => $env,
            'app' => $app,
            'timestamp' => $header['ts'],
            'level' => $header['level'],
            'logger' => $header['logger'],
            'correlation_id' => $this->matchFirst('/event:([0-9a-fA-F][0-9a-fA-F-]{7,})/', $header['rest']),
            'error_type' => $errorType,
            'error_type_namespace' => $errorType ? strtok($errorType, ':') : null,
            'message' => $message,
            'element' => $element,
            'flow_stack' => $this->extractFlowStack($body),
            'payload' => $fields['Payload'] ?? null,
            'root_exception' => $rootMessage,
            'stack_top' => $stackTop,
            // Extraction seeds (map to integration-error record fields):
            'http_method' => $this->matchFirst('/HTTP (GET|POST|PUT|PATCH|DELETE) on resource/', $message),
            'resource_url' => $this->matchFirst("/on resource '([^']+)'/", $message),
            'http_status' => $this->matchFirst('/(?:status code |\()(\d{3})\)?/', $message),
            'target_entity' => $this->matchFirst('#/v1/api/([a-z-]+)#', $message),
            'error_detail' => [], // filled in parseFile() from the correlated JSON details
            'raw' => trim($header['ts'].' '.$header['level'].' '.$header['logger'].' '.$header['rest'])."\n".$bodyText,
        ];
    }

    /**
     * @param  list<string>  $body
     * @return array<string,string>
     */
    private function extractFields(array $body): array
    {
        $fields = [];
        foreach ($body as $line) {
            if (preg_match(self::FIELD, $line, $m)) {
                $label = trim($m['label']);
                // FlowStack / Root Exception are multi-line — handled separately.
                if (! isset($fields[$label])) {
                    $fields[$label] = trim($m['value']);
                }
            }
        }

        return $fields;
    }

    /**
     * @param  list<string>  $body
     * @return list<string>
     */
    private function extractFlowStack(array $body): array
    {
        $frames = [];
        $inStack = false;
        foreach ($body as $line) {
            if (preg_match('/^FlowStack +: (.*)$/', $line, $m)) {
                $inStack = true;
                $frames[] = trim($m[1]);
                continue;
            }
            if ($inStack) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'at ')) {
                    $frames[] = $trimmed;
                } else {
                    break; // next field label or separator ends the flow stack
                }
            }
        }

        return $frames;
    }

    /**
     * @param  list<string>  $body
     * @return array{0: ?string, 1: list<string>}
     */
    private function extractRootException(array $body): array
    {
        $idx = null;
        foreach ($body as $i => $line) {
            if (str_starts_with(trim($line), 'Root Exception stack trace:')) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return [null, []];
        }

        $message = null;
        $frames = [];
        for ($i = $idx + 1; $i < count($body); $i++) {
            $trimmed = trim($body[$i]);
            if ($trimmed === '') {
                continue;
            }
            if (str_starts_with($trimmed, 'at ')) {
                $frames[] = $trimmed;
                if (count($frames) >= 6) {
                    break;
                }
            } elseif ($message === null) {
                $message = $trimmed;
            }
        }

        return [$message, $frames];
    }

    /**
     * Resolve the deployment environment for a case. An explicit override wins;
     * otherwise infer from the filename (CloudHub names UAT downloads like
     * "orders-papi-uat-instance-…"). Falls back to 'unknown' so prod vs UAT
     * stays a sliceable dimension rather than a silent assumption.
     */
    private function detectEnv(string $source, ?string $override): string
    {
        if ($override !== null && $override !== '') {
            return strtolower($override);
        }
        if (preg_match('/[-_.](prod|production|uat|sit|dev|test)[-_.]/i', $source, $m)) {
            $env = strtolower($m[1]);

            return $env === 'production' ? 'prod' : $env;
        }

        return 'unknown';
    }

    private function matchFirst(string $pattern, ?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        return preg_match($pattern, $subject, $m) ? $m[1] : null;
    }
}
