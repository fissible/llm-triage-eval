<?php

namespace App\Services\Triage;

/**
 * Redacts sensitive values from parsed error cases before they enter the golden
 * set (which is committed to the repo).
 *
 * Calibrated to what actually appears in the CS logs (a scan of ~4k cases found
 * no SSNs/emails/DOBs in the LLM-visible fields), so most rules are DEFENSIVE.
 * The one real leak vector is the Postgres duplicate-key Detail line, e.g.
 *   Detail: Key (person_guid)=(BE64E7277A) already exists
 * whose value is a real person GUID. That is redacted while KEEPING the column
 * name, which is the diagnostically useful part.
 *
 * Note: operational identifiers needed downstream (correlation_id, timestamps,
 * error_type, resource URLs, target entity) are intentionally preserved — they
 * are not PII and the extraction eval grades against them.
 */
class Sanitizer
{
    /** Fields sanitized in place (strings and arrays of strings). */
    private const FIELDS = [
        'message', 'payload', 'root_exception', 'element', 'raw', 'flow_stack', 'stack_top',
    ];

    public function sanitizeString(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return $s;
        }

        // Postgres "Detail: Key (col)=(value)" — keep the column, redact the value.
        $s = preg_replace('/(Key \([^)]*\)=\()[^)]*(\))/', '${1}[REDACTED]${2}', $s);

        // Postgres "Failing row contains (…)" — real row data (ids, dates, user ids).
        $s = preg_replace('/(Failing row contains )\([^)]*\)/', '${1}([REDACTED])', $s);

        // SSN (dashed) and 9-digit runs (defensive — none seen, but cheap insurance).
        $s = preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[SSN]', $s);
        $s = preg_replace('/\b\d{9,}\b/', '[NUM]', $s);

        // Emails.
        $s = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL]', $s);

        return $s;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    public function sanitizeCase(array $case): array
    {
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $case)) {
                continue;
            }
            $value = $case[$field];
            if (is_string($value)) {
                $case[$field] = $this->sanitizeString($value);
            } elseif (is_array($value)) {
                $case[$field] = array_map(fn ($v) => is_string($v) ? $this->sanitizeString($v) : $v, $value);
            }
        }

        // error_detail is a list of {code, message, description} objects.
        if (isset($case['error_detail']) && is_array($case['error_detail'])) {
            $case['error_detail'] = array_map(
                fn ($d) => is_array($d)
                    ? array_map(fn ($v) => is_string($v) ? $this->sanitizeString($v) : $v, $d)
                    : $d,
                $case['error_detail']
            );
        }

        return $case;
    }
}
