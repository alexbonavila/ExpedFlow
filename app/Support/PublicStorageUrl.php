<?php

namespace App\Support;

class PublicStorageUrl
{
    public static function from(mixed $appUrl): string
    {
        $base = is_string($appUrl) ? $appUrl : 'http://localhost';

        return rtrim($base, '/').'/storage';
    }
}
