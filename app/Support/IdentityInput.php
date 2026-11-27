<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

final class IdentityInput
{
    /** @return list<string> */
    public static function locales(): array
    {
        return ['es', 'ca', 'en'];
    }

    /** @return list<string> */
    public static function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /** @return list<string> */
    public static function emailRules(): array
    {
        return ['required', 'email', 'max:255'];
    }

    /** @return list<string|In> */
    public static function localeRules(): array
    {
        return ['required', 'string', Rule::in(self::locales())];
    }

    /** @return array<string, list<string>> */
    public static function credentials(): array
    {
        return [
            'email' => self::emailRules(),
            'password' => ['required', 'string', 'max:4096'],
        ];
    }

    public static function email(string $email): string
    {
        return Str::lower(Str::trim($email));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function validate(array $input, array $rules): array
    {
        if (is_string($input['email'] ?? null)) {
            $input['email'] = self::email($input['email']);
        }

        return Validator::make($input, $rules)->validate();
    }

    /**
     * @return list<string|Closure(string, mixed, Closure): void>
     */
    public static function newPassword(): array
    {
        return [
            'required', 'string', 'min:12', 'max:1024',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && str_contains($value, "\0")) {
                    $fail('identity.password_unsupported')->translate();
                }

                if (is_string($value) && Hash::getDefaultDriver() === 'bcrypt' && strlen($value) > 72) {
                    $fail('identity.password_bytes')->translate();
                }
            },
        ];
    }
}
