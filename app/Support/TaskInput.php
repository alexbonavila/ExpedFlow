<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class TaskInput
{
    /** @return array<string, mixed> */
    public static function fields(Request $request): array
    {
        $payload = json_decode($request->getContent());
        if (! $request->isJson() || ! is_object($payload)) {
            throw ValidationException::withMessages(['body' => __('tasks.task_json_object_required')]);
        }

        return get_object_vars($payload);
    }

    public static function textLimit(string $attribute, string $value, Closure $fail): void
    {
        if (strlen($value) > 65_535) {
            $fail('tasks.task_text_too_long')->translate();
        }
    }

    public static function civilDate(string $attribute, string $value, Closure $fail): void
    {
        if (! preg_match('/\A([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $fail('tasks.task_date_invalid')->translate();
        }
    }
}
