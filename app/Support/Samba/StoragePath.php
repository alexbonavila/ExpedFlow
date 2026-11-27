<?php

namespace App\Support\Samba;

use Normalizer;

final class StoragePath
{
    private const UNSAFE = '~[\p{Cc}\p{Cf}<>:"/\\\\|?*]~u';

    private const RESERVED = '/^(?:CON|PRN|AUX|NUL|CONIN\$|CONOUT\$|(?:COM|LPT)[1-9¹²³])(?:[ .]|$)/iu';

    public static function isComponent(string $name): bool
    {
        return $name !== '' && strlen($name) <= 255
            && preg_match(self::UNSAFE, $name) === 0
            && preg_match(self::RESERVED, $name) === 0
            && ! str_ends_with($name, '.') && ! str_ends_with($name, ' ');
    }

    public static function isUserComponent(string $name): bool
    {
        return self::isComponent($name) && ! str_starts_with(self::fold($name), '.expedflow-');
    }

    public static function fold(string $name): string
    {
        $normalized = Normalizer::normalize($name);

        return mb_convert_case($normalized === false ? $name : $normalized, MB_CASE_FOLD, 'UTF-8');
    }

    public static function isRoot(string $path): bool
    {
        return strlen($path) <= 512 && count(explode('/', $path)) <= 8
            && array_all(explode('/', $path), self::isUserComponent(...));
    }

    public static function caseComponent(string $code, string $name): string
    {
        if (preg_match('/^[0-9]{9}$/D', $code) !== 1) {
            throw new StorageFailure(Failure::Unsafe);
        }

        $normalized = Normalizer::normalize($name);
        $safe = preg_replace(self::UNSAFE, '_', $normalized === false ? '' : $normalized);
        $safe = trim(mb_strcut($safe ?? '', 0, 245, 'UTF-8'), ' .');

        return $code.' '.($safe === '' ? '_' : $safe);
    }
}
