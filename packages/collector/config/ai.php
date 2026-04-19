<?php

return [

    'default' => 'openrouter',

    'default_for_images' => 'openai',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    'providers' => [
        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
            'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        ],
    ],

    // データ収集モジュール用クローラー設定（Laravel AI SDK とは独立）
    'crawler' => [
        'timeout' => (int) env('CRAWLER_TIMEOUT', 30),
        'rate_limit_ms' => (int) env('CRAWLER_RATE_LIMIT_MS', 1000),
        'max_depth' => (int) env('CRAWLER_MAX_DEPTH', 3),
        'user_agent' => env('CRAWLER_USER_AGENT', 'Mozilla/5.0 (compatible; ChildcareBot/1.0)'),
        'respect_robots' => (bool) env('CRAWLER_RESPECT_ROBOTS', true),
        'max_html_chars' => (int) env('CRAWLER_MAX_HTML_CHARS', 12000),
    ],

];
