<?php

// The loopback harness delivers built assets with the same header as Apache.
$public = __DIR__.'/../../public';
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($public.rawurldecode(is_string($uri) ? $uri : '/'));
$types = [
    'css' => 'text/css',
    'ico' => 'image/x-icon',
    'js' => 'text/javascript',
    'svg' => 'image/svg+xml',
    'txt' => 'text/plain',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
];
$build = realpath($public.'/build');
$staticFiles = [realpath($public.'/favicon.ico'), realpath($public.'/robots.txt')];

if (is_string($asset) && is_file($asset) && (in_array($asset, $staticFiles, true) || (is_string($build) && str_starts_with($asset, $build.'/')))) {
    $type = $types[pathinfo($asset, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: '.$type);
    header('Content-Length: '.filesize($asset));

    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        readfile($asset);
    }

    return;
}

require $public.'/index.php';
