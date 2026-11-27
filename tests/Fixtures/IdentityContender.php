<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestingDatabase;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
TestingDatabase::assertSafe($app);

$operation = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$marker = isset($operation['bootstrap']) ? 'insert ignore into `roles`' : 'for update';
DB::connection()->beforeExecuting(static function (string $query) use ($marker): void {
    if (str_contains(strtolower($query), $marker)) {
        fwrite(STDOUT, "ready\n");
        fflush(STDOUT);
    }
});

if (isset($operation['bootstrap'])) {
    config(['identity.bootstrap' => $operation['bootstrap']]);
    $result = ['exit' => Artisan::call('expedflow:admin:init')];
} else {
    $request = Request::create('https://expedflow.example'.$operation['path'], 'POST', server: [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $operation['authorization'] ?? '',
    ], content: json_encode($operation['body'], JSON_THROW_ON_ERROR));

    $response = $app->make(HttpKernel::class)->handle($request);
    $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $result = ['status' => $response->getStatusCode(), 'code' => $body['code'] ?? null];
}

fwrite(STDOUT, 'result:'.json_encode($result, JSON_THROW_ON_ERROR)."\n");
