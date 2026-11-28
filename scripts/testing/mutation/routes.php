<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo json_encode(array_values(array_map(
    static fn (Route $route): array => [
        $route->uri(), $route->methods(), $route->getActionName(),
        $route->gatherMiddleware(), $route->wheres, $route->getDomain(),
    ],
    $app->make('router')->getRoutes()->getRoutes(),
)), JSON_THROW_ON_ERROR), PHP_EOL;
