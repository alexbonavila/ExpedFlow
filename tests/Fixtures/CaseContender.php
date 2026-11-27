<?php

use App\Support\ExpedientLifecycle;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestingDatabase;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
TestingDatabase::assertSafe($app);

$operation = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (isset($operation['clock'])) {
    config(['app.timezone' => 'UTC']);
    Date::setTestNow(Date::parse($operation['clock']));
}
DB::connection()->beforeExecuting(static function (string $query) use ($operation): void {
    if (str_contains(strtolower($query), $operation['barrier'])) {
        fwrite(STDOUT, "ready\n");
        fflush(STDOUT);
    }
});

if (isset($operation['parent'])) {
    try {
        DB::transaction(function () use ($app, $operation): void {
            $parent = $app->make(ExpedientLifecycle::class)->lockParent($operation['parent']);
            $parent->tasks()->forceCreate([
                'name' => 'Contending synthetic task', 'status_id' => $parent->status_id, 'position' => 1,
            ]);
        }, 3);
        $result = ['status' => 201];
    } catch (ModelNotFoundException) {
        $result = ['status' => 404];
    }
} else {
    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $operation['authorization'] ?? '',
    ];
    $browser = $operation['browser'] ?? null;
    if ($browser !== null) {
        config(['sanctum.stateful' => ['expedflow.example'], 'session.driver' => 'database', 'session.secure' => true]);
        $app->instance('env', 'production');
        $server += ['HTTP_ORIGIN' => 'https://expedflow.example', 'HTTP_X_XSRF_TOKEN' => $browser['csrf']];
    }

    $request = Request::create('https://expedflow.example'.$operation['path'], $operation['method'],
        cookies: $browser['cookies'] ?? [], server: $server, content: json_encode($operation['body'] ?? [], JSON_THROW_ON_ERROR));
    $response = $app->make(HttpKernel::class)->handle($request);
    $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $result = ['status' => $response->getStatusCode(), 'code' => $body['code'] ?? null, 'data' => $body['data'] ?? null];
}

fwrite(STDOUT, 'result:'.json_encode($result, JSON_THROW_ON_ERROR)."\n");
