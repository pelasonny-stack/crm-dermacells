<?php

/**
 * CORS configuration — CRM Dermacells
 *
 * Sanctum SPA cookie auth requires:
 *   - supports_credentials: true
 *   - allowed_origins MUST be explicit (no wildcard when credentials=true)
 *
 * Dev: http://localhost:5173 (Vite), http://127.0.0.1:5173
 * Prod: set CORS_ALLOWED_ORIGINS in .env (comma-separated)
 */

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'auth/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(
        array_map(
            'trim',
            explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173')),
        ),
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
