<?php

namespace App\Services\Triage;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * LLM-as-judge: scores a summary on faithfulness + completeness (1–5) against the
 * evidence. NOTE: judge and summarizer use the same local model here, so validate
 * the judge against human scores ("judge the judge") before trusting it.
 */
class SummaryJudge
{
    /**
     * @param  array<string,mixed>  $input
     * @return array{faithfulness:int, completeness:int, note:?string, prompt_tokens:int, completion_tokens:int}
     */
    public function judge(array $input, string $summary, string $version = 'v1'): array
    {
        $schema = new ObjectSchema(
            name: 'judgement',
            description: 'Scores for the candidate summary.',
            properties: [
                new NumberSchema('faithfulness', '1-5: are all claims grounded in the evidence?'),
                new NumberSchema('completeness', '1-5: captures operation, entity/app, and root cause?'),
                new StringSchema('note', 'one-line justification'),
            ],
            requiredFields: ['faithfulness', 'completeness', 'note'],
        );

        $response = Prism::structured()
            ->using(Provider::from(config('triage.provider')), config('triage.model'))
            ->usingTemperature(0)
            ->withSystemPrompt((string) view("prompts.judge-{$version}-system"))
            ->withPrompt((string) view("prompts.judge-{$version}", ['case' => $input, 'summary' => $summary]))
            ->withSchema($schema)
            ->withClientOptions(['timeout' => config('triage.timeout')])
            ->asStructured();

        $d = $response->structured ?? [];

        return [
            'faithfulness' => (int) ($d['faithfulness'] ?? 0),
            'completeness' => (int) ($d['completeness'] ?? 0),
            'note' => $d['note'] ?? null,
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
        ];
    }
}
