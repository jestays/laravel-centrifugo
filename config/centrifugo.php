<?php

return [
    'url' => env('CENTRIFUGO_URL', 'http://localhost:8000'),

    'api_key' => env('CENTRIFUGO_API_KEY'),

    'token_hmac_secret_key' => env('CENTRIFUGO_TOKEN_HMAC_SECRET_KEY'),

    'application' => env('CENTRIFUGO_APP'),

    'token_ttl' => (int) env('CENTRIFUGO_TOKEN_TTL', 3600),

    'verify' => (bool) env('CENTRIFUGO_VERIFY', true),

    'namespaces' => [
        'public' => 'public',
        'private' => 'private',
        'presence' => 'presence',
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'centrifugo',
        'middleware' => ['web', 'auth'],
    ],
];
