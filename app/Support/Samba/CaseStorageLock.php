<?php

namespace App\Support\Samba;

use Closure;

final class CaseStorageLock
{
    /**
     * The private lock directory must have one shared inode namespace across the installation's PHP workers.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(int $caseId, Closure $operation): mixed
    {
        $directory = storage_path('framework/cache/samba');
        if (! is_dir($directory) && ! @mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new StorageFailure(Failure::Transfer);
        }
        clearstatcache(true);
        if (realpath($directory) !== $directory) {
            throw new StorageFailure(Failure::Unsafe);
        }

        $slot = substr(hash('sha256', (string) $caseId), 0, 3);
        $lease = @fopen($directory.'/'.$slot.'.lock', 'cbe');
        if ($lease === false) {
            throw new StorageFailure(Failure::Transfer);
        }
        try {
            if (! @flock($lease, LOCK_EX | LOCK_NB, $wouldBlock)) {
                throw new StorageFailure($wouldBlock === 1 ? Failure::Busy : Failure::Transfer);
            }

            return $operation();
        } finally {
            fclose($lease);
        }
    }
}
