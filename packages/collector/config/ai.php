<?php

return [
    'default' => env('DEFAULT_LLM_PROVIDER', 'openrouter'),

    'providers' => [
        'openrouter' => [
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'api_key'  => env('OPENROUTER_API_KEY'),
        ],
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        ],
    ],
];
