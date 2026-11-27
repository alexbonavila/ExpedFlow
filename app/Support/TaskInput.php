<?php

namespace App\Support;

use Closure;

final class TaskInput
{
    public static function textLimit(string $attribute, string $value, Closure $fail): void
    {
        if (strlen($value) > 65_535) {
            $fail('identity.task_text_too_long')->translate();
        }
    }

    public static function civilDate(string $attribute, string $value, Closure $fail): void
    {
        if (! preg_match('/\A([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $fail('identity.task_date_invalid')->translate();
        }
    }
}
