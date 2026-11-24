<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_merge([
        // Applications Android (https://localhost) et iOS (capacitor://localhost)
        'https://localhost',
        'capacitor://localhost',
    ], env('APP_ENV') === 'local' ? [
        // Serveurs de développement : jamais en préproduction ni en production
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:8000',
    ] : []),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
