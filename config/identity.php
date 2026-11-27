<?php

return [
    'bootstrap' => [
        'name' => env('ADMIN_NAME'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],
    'token_days' => (int) env('AUTH_TOKEN_DAYS', 30),
    'limits' => [
        'attempts_per_identity' => (int) env('AUTH_ATTEMPTS_PER_IDENTITY', 5),
        'attempts_per_ip' => (int) env('AUTH_ATTEMPTS_PER_IP', 30),
    ],
];
