<?php

return [
    // Local-first via Ollama; swap to 'anthropic' + a model id to benchmark Claude
    // (Prism makes it a two-value change — the eval harness then MEASURES the gap).
    'provider' => env('TRIAGE_PROVIDER', 'ollama'),
    'model' => env('TRIAGE_MODEL', 'gemma3:12b'),

    // Which prompt template pair to use: prompts.classify-{version}-system + -{version}.
    'prompt_version' => env('TRIAGE_PROMPT_VERSION', 'v1'),

    // Local models can be slow on long inputs; give them room.
    'timeout' => (int) env('TRIAGE_TIMEOUT', 180),

    // $ per 1M tokens, for cost reporting. Local = free; fill in when benchmarking a
    // hosted model so reports carry real spend numbers.
    'cost_per_mtok' => [
        'input' => (float) env('TRIAGE_COST_IN', 0),
        'output' => (float) env('TRIAGE_COST_OUT', 0),
    ],

    // Base URL for deep-linking a case to Anypoint Runtime Manager by correlation id.
    // Set TRIAGE_ANYPOINT_URL to your org's log-search URL; {cid} is substituted.
    'anypoint_search_url' => env('TRIAGE_ANYPOINT_URL', ''),
];
