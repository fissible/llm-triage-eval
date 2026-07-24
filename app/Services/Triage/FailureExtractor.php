<?php

namespace App\Services\Triage;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Extracts integration-error-style structured fields from a raw failure via
 * Prism structured output. Same provider/model/temp-0 discipline as the classifier.
 */
class FailureExtractor
{
    public const FIELDS = ['http_method', 'http_status', 'target_entity', 'error_type', 'operation', 'constraint'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>  the six fields + prompt/completion tokens
     */
    public function extract(array $input, string $version = ''): array
    {
        $version = $version ?: 'v1';

        $schema = new ObjectSchema(
            name: 'extraction',
            description: 'Structured fields pulled from the raw failure.',
            properties: [
                new StringSchema('http_method', 'HTTP verb (GET/POST/PUT/PATCH/DELETE) or null.', nullable: true),
                new StringSchema('http_status', 'Numeric HTTP status (e.g. 500, 601) or null.', nullable: true),
                new StringSchema('target_entity', 'API entity being acted on, or null.', nullable: true),
                new StringSchema('error_type', 'Mule error type/code, or null.', nullable: true),
                new EnumSchema('operation', 'The failed data operation.', ['insert', 'update', 'delete', 'get', 'patch', 'post', 'none']),
                new StringSchema('constraint', 'Violated DB constraint name, or null.', nullable: true),
            ],
            requiredFields: self::FIELDS,
        );

        $response = Prism::structured()
            ->using(Provider::from(config('triage.provider')), config('triage.model'))
            ->usingTemperature(0)
            ->withSystemPrompt((string) view("prompts.extract-{$version}-system"))
            ->withPrompt((string) view("prompts.extract-{$version}", ['case' => $input]))
            ->withSchema($schema)
            ->withClientOptions(['timeout' => config('triage.timeout')])
            ->asStructured();

        $d = $response->structured ?? [];

        return [
            'http_method' => $d['http_method'] ?? null,
            'http_status' => isset($d['http_status']) ? (string) $d['http_status'] : null,
            'target_entity' => $d['target_entity'] ?? null,
            'error_type' => $d['error_type'] ?? null,
            'operation' => $d['operation'] ?? 'none',
            'constraint' => $d['constraint'] ?? null,
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
        ];
    }
}
