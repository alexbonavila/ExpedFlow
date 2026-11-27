<?php

use App\Support\Samba\CaseStorageLock;
use App\Support\Samba\Failure;
use App\Support\Samba\StorageFailure;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->lockStorage = sys_get_temp_dir().'/expedflow-lock-'.bin2hex(random_bytes(8));
    mkdir($this->lockStorage.'/framework/cache', 0700, true);
    $this->app->useStoragePath($this->lockStorage);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->lockStorage);
});

test('returns the protected result and releases the case lock after a callback throws', function (): void {
    $lock = app(CaseStorageLock::class);
    $caseId = 6001;
    $failure = new RuntimeException('The protected operation failed.');

    expect($lock->run($caseId, fn (): string => 'completed'))->toBe('completed');
    expect(fn () => $lock->run($caseId, fn (): never => throw $failure))->toThrow($failure);

    $holder = new Process([PHP_BINARY, 'tests/Fixtures/SambaLockHolder.php', (string) $caseId, $this->lockStorage], base_path());
    expect($holder->setTimeout(8)->setInput("release\n")->mustRun()->getOutput())->toBe("locked\n");
    expect($holder->isRunning())->toBeFalse();
})->group('samba-lock');

test('reports transfer failure when the local lease file cannot be opened', function (): void {
    $lock = app(CaseStorageLock::class);
    $caseId = 6002;
    $lock->run($caseId, fn (): bool => true);
    $lease = glob($this->lockStorage.'/framework/cache/samba/*.lock')[0];
    unlink($lease);
    mkdir($lease);

    expect(fn () => $lock->run($caseId, function (): never {
        throw new LogicException('Storage work started without a lease.');
    }))->toThrow(StorageFailure::class, Failure::Transfer->value);
})->group('samba-lock');
