<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\TestingDatabase;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';

try {
    $app->make(Kernel::class)->bootstrap();
    TestingDatabase::assertSafe($app);
} catch (PDOException) {
    fwrite(STDERR, "Could not verify the dedicated MySQL testing connection.\n");
    exit(1);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Could not bootstrap the testing application.\n");
    exit(1);
}
