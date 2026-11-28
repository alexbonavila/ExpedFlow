<?php

namespace App\Support\Samba;

final class LinuxMountVerifier implements MountVerifier
{
    public function mountId(StorageConfiguration $configuration): int
    {
        clearstatcache(true);
        $contents = @file_get_contents('/proc/self/mountinfo');

        if ($contents === false || realpath($configuration->mountpoint) !== $configuration->mountpoint) {
            throw new StorageFailure(Failure::Unavailable);
        }

        return self::verify($configuration, $contents);
    }

    public static function verify(StorageConfiguration $configuration, string $mountinfo): int
    {
        $mount = null;
        $devices = [];
        foreach (explode("\n", rtrim($mountinfo, "\n")) as $line) {
            $fields = explode(' - ', $line);
            $head = explode(' ', $fields[0]);
            $tail = explode(' ', $fields[1] ?? '');
            if (count($fields) !== 2 || count($head) < 6 || count($tail) !== 3
                || preg_match('/^[1-9][0-9]*$/D', $head[0]) !== 1
                || preg_match('/^[0-9]+:[0-9]+$/D', $head[2]) !== 1) {
                throw new StorageFailure(Failure::Unavailable);
            }

            [$id, , $device, $root, $target, $options] = $head;
            $target = self::decode($target);
            $devices[$device] = ($devices[$device] ?? 0) + 1;
            if (str_starts_with($target, $configuration->mountpoint.'/')) {
                throw new StorageFailure(Failure::Unavailable);
            }
            if ($target !== $configuration->mountpoint) {
                continue;
            }
            $flags = explode(',', $options);
            $serverFlags = explode(',', $tail[2]);
            if ($mount !== null || $root !== '/' || ! in_array($tail[0], ['cifs', 'smb3'], true)
                || self::decode($tail[1]) !== $configuration->source
                || array_diff(['rw', 'nodev', 'nosuid', 'noexec'], $flags) !== []
                || array_diff(['rw', 'cache=none'], $serverFlags) !== []
                || in_array('nostrictsync', $serverFlags, true)) {
                throw new StorageFailure(Failure::Unavailable);
            }
            $mount = [(int) $id, $device];
        }
        if ($mount === null || $devices[$mount[1]] !== 1) {
            throw new StorageFailure(Failure::Unavailable);
        }

        return $mount[0];
    }

    private static function decode(string $field): string
    {
        $escaped = str_replace(['\\040', '\\011', '\\012', '\\134'], '', $field);
        if (str_contains($escaped, '\\')) {
            throw new StorageFailure(Failure::Unavailable);
        }

        return strtr($field, ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
    }
}
