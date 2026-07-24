<?php

namespace App\Services\Triage;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

/** Generates a one-sentence, evidence-grounded incident summary via the LLM. */
class FailureSummarizer
{
    /**
     * @param  array<string,mixed>  $input
     * @return array{summary:string, prompt_tokens:int, completion_tokens:int}
     */
    public function summarize(array $input, string $version = 'v1'): array
    {
        $response = Prism::text()
            ->using(Provider::from(config('triage.provider')), config('triage.model'))
            ->usingTemperature(0)
            ->withSystemPrompt((string) view("prompts.summarize-{$version}-system"))
            ->withPrompt((string) view("prompts.summarize-{$version}", ['case' => $input]))
            ->withClientOptions(['timeout' => config('triage.timeout')])
            ->asText();

        return [
            'summary' => trim($response->text),
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
        ];
    }

    /**
     * Explain a whole transaction (all its ordered events across apps) in plain English.
     *
     * @param  list<array<string,mixed>>  $events  ordered [app, error_type, message, occurred_at, error_detail]
     * @return array{explanation:string, prompt_tokens:int, completion_tokens:int}
     */
    public function explainChain(string $correlation, array $events, string $version = 'v1'): array
    {
        $response = Prism::text()
            ->using(Provider::from(config('triage.provider')), config('triage.model'))
            ->usingTemperature(0)
            ->withSystemPrompt((string) view("prompts.explain-chain-{$version}-system"))
            ->withPrompt((string) view("prompts.explain-chain-{$version}", ['correlation' => $correlation, 'events' => $events]))
            ->withClientOptions(['timeout' => config('triage.timeout')])
            ->asText();

        return [
            'explanation' => trim($response->text),
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
        ];
    }
}

