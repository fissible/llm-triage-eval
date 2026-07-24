<?php

namespace App\Enums;

/**
 * Failure taxonomy for the integration layer.
 *
 * Seeded from real MuleSoft (CloudHub) error output but named at the
 * *integration-layer* level so the same categories apply as functionality
 * moves to another platform. This is the classification label space:
 * the golden set is labeled with these, the LLM classifier predicts these,
 * and the confusion matrix is computed over these.
 */
enum FailureCategory: string
{
    case DbTimeout = 'db_timeout';
    case DbConstraintViolation = 'db_constraint_violation';
    case DownstreamDbWriteFailure = 'downstream_db_write_601';
    case DownstreamServerError = 'downstream_500_cascade';
    case DownstreamTimeout = 'downstream_timeout';
    case ExpressionError = 'dataweave_expression_error';
    case BusinessValidation = 'business_validation';
    case StreamingAuth = 'sf_streaming_auth';
    case CompositeRouting = 'composite_routing';
    case Connectivity = 'connectivity';
    case DownstreamClientError = 'downstream_client_error';
    case MalformedRequest = 'malformed_request';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::DbTimeout => 'Legacy DB timeout (LegacyDB/JDBC read timed out)',
            self::DbConstraintViolation => 'DB constraint violation (duplicate key / not-null)',
            self::DownstreamDbWriteFailure => 'Downstream DB write failure (HTTP 601)',
            self::DownstreamServerError => 'Downstream 500 cascade',
            self::DownstreamTimeout => 'Timeout calling a downstream Mule app',
            self::ExpressionError => 'DataWeave / expression error',
            self::BusinessValidation => 'Business validation failure (e.g. missing legacy ID)',
            self::StreamingAuth => 'CRM streaming/session auth / session 403',
            self::CompositeRouting => 'Composite-routing aggregate failure',
            self::Connectivity => 'Connectivity failure',
            self::DownstreamClientError => 'Downstream client error (HTTP 4xx / bad request)',
            self::MalformedRequest => 'Malformed request (bad URI / schema / config or code bug)',
            self::Unknown => 'Unknown / uncategorized',
        };
    }

    /** Values as a plain array — handy for a Prism EnumSchema. */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
