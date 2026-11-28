<?php

declare(strict_types=1);

namespace App\Support\Samba;

use Pest\Mutate\Support\StreamWrapper;

/**
 * Pest 5.0.2 forwards flock on its wrapped files as a blocking LOCK_SH.
 * Open only the case lease on the native wrapper, retaining mutant source loading.
 *
 * @param  resource|null  $context
 * @return resource|false
 */
function fopen(string $filename, string $mode, bool $use_include_path = false, mixed $context = null): mixed
{
    if ((debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? null) !== CaseStorageLock::class) {
        return \fopen($filename, $mode, $use_include_path, $context);
    }

    StreamWrapper::disable();
    try {
        return \fopen($filename, $mode, $use_include_path, $context);
    } finally {
        StreamWrapper::enable();
    }
}
