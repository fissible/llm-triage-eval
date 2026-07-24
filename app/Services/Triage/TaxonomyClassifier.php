<?php

namespace App\Services\Triage;

use App\Enums\FailureCategory;

/**
 * Deterministic, rule-based classifier over parsed error cases.
 *
 * Two jobs:
 *  1. WEAK LABELER — pre-tags every parsed case so building the golden set is a
 *     *review* task (confirm/correct ~80 cases) instead of blind hand-labeling.
 *  2. BASELINE — this is the cheap, zero-cost baseline the LLM classifier has to
 *     beat. If a temperature-0 LLM can't out-perform regexes on your own logs,
 *     that is itself a finding worth reporting.
 *
 * Rules are ordered specific → generic; the first match wins.
 */
class TaxonomyClassifier
{
    /**
     * @param  array<string,mixed>  $case  A record from MuleLogParser.
     */
    public function classify(array $case): FailureCategory
    {
        $type = (string) ($case['error_type'] ?? '');
        $msg = (string) ($case['message'] ?? '');
        $root = (string) ($case['root_exception'] ?? '');
        $url = (string) ($case['resource_url'] ?? '');

        // The correlated JSON details often hold the true root cause behind a
        // generic ERROR (e.g. a COMPOSITE_ROUTING/601 wrapper whose real cause is a
        // constraint violation), so fold them all into what we classify on.
        $detailText = '';
        foreach (($case['error_detail'] ?? []) as $de) {
            if (is_array($de)) {
                $detailText .= "\n".implode("\n", array_filter([
                    $de['code'] ?? null, $de['message'] ?? null, $de['description'] ?? null,
                ]));
            }
        }

        $haystack = "{$msg}\n{$root}\n{$detailText}";

        $isDownstreamMule = str_contains($url, 'mule-worker-internal');

        // --- DB constraint violations (very specific PSQL signatures) ---
        // Only the specific constraint signatures — a bare PSQLException is left to
        // fall through (it may be a 601 wrap or genuinely uncategorized) so the weak
        // label never *asserts* a constraint violation it can't see.
        if ($this->hasAny($haystack, [
            'duplicate key value violates unique constraint',
            'violates not-null constraint',
            'violates foreign key constraint',
            'violates check constraint',
        ])) {
            return FailureCategory::DbConstraintViolation;
        }

        // --- Timeouts: distinguish legacy-DB from downstream-Mule ---
        if (str_ends_with($type, ':TIMEOUT') || $this->hasAny($haystack, [
            'Read timed out',
            'Timeout exceeded',
            'SocketTimeoutException',
            'TimeoutException',
        ])) {
            if ($isDownstreamMule || str_contains($type, 'ORDERS-PROCESS-API')) {
                return FailureCategory::DownstreamTimeout;
            }
            if (str_contains($type, 'INVENTORY') || str_contains($haystack, 'legacydb') || str_contains($haystack, 'jdbc')) {
                return FailureCategory::DbTimeout;
            }

            return FailureCategory::DownstreamTimeout;
        }

        // --- DataWeave / expression errors ---
        if ($type === 'MULE:EXPRESSION' || $this->hasAny($haystack, [
            'UnexpectedFunctionCallTypesException',
            'you called the function',
            'DataWeave',
        ])) {
            return FailureCategory::ExpressionError;
        }

        // --- Malformed request: bad URI, unsubstituted template, or inbound schema mismatch ---
        if (str_contains($type, 'APIKIT:BAD_REQUEST')
            || $this->hasAny($haystack, ['URISyntaxException', 'Illegal character in', 'expected type:'])) {
            return FailureCategory::MalformedRequest;
        }

        // --- CRM streaming/session auth ---
        if ($this->hasAny($haystack, ['Unknown client', 'INVALID_SESSION', 'session invalid']) ||
            (str_contains($haystack, '403') && str_contains($haystack, 'client'))) {
            return FailureCategory::StreamingAuth;
        }

        // --- Business validation ---
        if (str_contains($type, ':VALIDATION') || str_contains($haystack, 'requires a valid legacy')) {
            return FailureCategory::BusinessValidation;
        }

        // --- HTTP 601: custom "failed to write to DB/SF" ---
        if (str_contains($msg, '601')) {
            return FailureCategory::DownstreamDbWriteFailure;
        }

        // --- Downstream returned an HTTP 4xx (client error / bad request) ---
        $status = (int) ($case['http_status'] ?? 0);
        if (($status >= 400 && $status < 500) || $this->hasAny($haystack, ['bad request (4'])) {
            return FailureCategory::DownstreamClientError;
        }

        // --- Downstream 500 cascade ---
        if (str_ends_with($type, ':INTERNAL_SERVER_ERROR') ||
            str_contains($haystack, 'internal server error (500)') ||
            (string) ($case['http_status'] ?? '') === '500') {
            return FailureCategory::DownstreamServerError;
        }

        // --- Composite routing aggregate ---
        if ($type === 'MULE:COMPOSITE_ROUTING') {
            return FailureCategory::CompositeRouting;
        }

        // --- Connectivity ---
        if (str_ends_with($type, ':CONNECTIVITY') || str_contains($haystack, 'ConnectException')) {
            return FailureCategory::Connectivity;
        }

        return FailureCategory::Unknown;
    }

    private function hasAny(string $haystack, array $needles): bool
    {
        $lc = strtolower($haystack);
        foreach ($needles as $needle) {
            if (str_contains($lc, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
