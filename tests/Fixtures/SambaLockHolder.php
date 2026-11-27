<?php

use App\Support\Samba\CaseStorageLock;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
if (isset($argv[2])) {
    $app->useStoragePath($argv[2]);
}

$app->make(CaseStorageLock::class)->run((int) ($argv[1] ?? 0), static function (): void {
    fwrite(STDOUT, "locked\n");
    fflush(STDOUT);
    $read = [STDIN];
    $write = $except = null;
    if (stream_select($read, $write, $except, 4) !== 1 || fgets(STDIN) !== "release\n") {
        throw new RuntimeException('Storage lock holder did not receive a release signal.');
    }
});
