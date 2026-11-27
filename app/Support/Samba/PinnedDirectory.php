<?php

namespace App\Support\Samba;

/** A Linux directory descriptor owned by this operation. */
final class PinnedDirectory
{
    /** @param resource $handle */
    private function __construct(
        private $handle,
        public readonly string $path,
        public readonly int $device,
        public readonly int $mountId,
    ) {}

    public function __destruct()
    {
        @fclose($this->handle);
    }

    public static function open(string $path, int $mountId): self
    {
        clearstatcache(true);
        $expected = @lstat($path);
        if ($expected === false || ($expected['mode'] & 0170000) !== 0040000) {
            throw new StorageFailure(Failure::Unsafe);
        }

        $before = self::descriptors();
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new StorageFailure(Failure::Unavailable);
        }

        try {
            $actual = @fstat($handle);
            if ($actual === false || $actual['dev'] !== $expected['dev'] || $actual['ino'] !== $expected['ino']
                || ($actual['mode'] & 0170000) !== 0040000) {
                throw new StorageFailure(Failure::Unsafe);
            }

            foreach (array_diff_key(self::descriptors(), $before) as $descriptor => $target) {
                $fd = '/proc/self/fd/'.$descriptor;
                $info = @file_get_contents('/proc/self/fdinfo/'.$descriptor);
                $stat = @stat($fd);
                if ($stat !== false && $stat['dev'] === $actual['dev'] && $stat['ino'] === $actual['ino']
                    && is_string($info) && preg_match('/^mnt_id:\s+'.$mountId.'$/m', $info) === 1) {
                    return new self($handle, $fd, $actual['dev'], $mountId);
                }
            }
            throw new StorageFailure(Failure::Unavailable);
        } catch (StorageFailure $failure) {
            @fclose($handle);
            throw $failure;
        }
    }

    /** @return array<int, string> */
    private static function descriptors(): array
    {
        $entries = @scandir('/proc/self/fd');
        if ($entries === false) {
            throw new StorageFailure(Failure::Unavailable);
        }
        $descriptors = [];
        foreach ($entries as $entry) {
            if (ctype_digit($entry)) {
                $target = @readlink('/proc/self/fd/'.$entry);
                if ($target !== false) {
                    $descriptors[(int) $entry] = $target;
                }
            }
        }

        return $descriptors;
    }
}
