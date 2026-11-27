<?php

$url = parse_url((string) env('APP_URL', 'http://localhost'));

return [
    'stateful' => [($url['host'] ?? 'localhost').(isset($url['port']) ? ':'.$url['port'] : '')],
];
