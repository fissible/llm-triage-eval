<?php

namespace App\Services\Triage;

use App\Enums\FailureCategory;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * LLM classifier: parsed error case → FailureCategory, via Prism structured output.
 *
 * Provider/model come from config('triage.*') so the same harness can run local
 * (Ollama) or hosted (Anthropic) unchanged — that swap is the whole point: the
 * eval report then measures local-vs-Claude on the golden set. Temperature 0 for
 * determinism; the schema forces a valid taxonomy key so we never parse free text.
 */
class FailureClassifier
{
    public function __construct(
        private readonly string $provider = '',
        private readonly string $model = '',
        private readonly string $promptVersion = '',
    ) {}

    /**
     * @param  array<string,mixed>  $input  A golden-set `input` block.
     * @return array{category: string, rationale: ?string, prompt_tokens: int, completion_tokens: int}
     */
    public function classify(array $input, string $version = ''): array
    {
        $version = $version ?: ($this->promptVersion ?: config('triage.prompt_version'));

        $schema = new ObjectSchema(
            name: 'classification',
            description: 'The single best failure category and a one-sentence rationale.',
            properties: [
                new EnumSchema('category', 'One taxonomy key.', FailureCategory::values()),
                new StringSchema('rationale', 'One sentence explaining the choice.'),
            ],
            requiredFields: ['category', 'rationale'],
        );

        $response = Prism::structured()
            ->using(
                Provider::from($this->provider ?: config('triage.provider')),
                $this->model ?: config('triage.model'),
            )
            ->usingTemperature(0)
            ->withSystemPrompt((string) view("prompts.classify-{$version}-system", [
                'categories' => $this->categoryMap(),
            ]))
            ->withPrompt((string) view("prompts.classify-{$version}", ['case' => $input]))
            ->withSchema($schema)
            ->withClientOptions(['timeout' => config('triage.timeout')])
            ->asStructured();

        $data = $response->structured ?? [];
        $category = FailureCategory::tryFrom($data['category'] ?? '') ?? FailureCategory::Unknown;

        return [
            'category' => $category->value,
            'rationale' => $data['rationale'] ?? null,
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
        ];
    }

    /** @return array<string,string> */
    private function categoryMap(): array
    {
        $map = [];
        foreach (FailureCategory::cases() as $c) {
            $map[$c->value] = $c->label();
        }

        return $map;
    }
}
