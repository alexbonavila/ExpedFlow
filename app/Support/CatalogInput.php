<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CatalogInput
{
    /**
     * @param  array<string, list<string|Closure>>  $rules
     * @return array<string, mixed>
     */
    public static function validate(Request $request, array $rules): array
    {
        $fields = AuthorizationInput::fields($request);
        if (is_string($fields['name'] ?? null)) {
            $fields['name'] = Str::trim($fields['name']);
        }

        $unknown = array_diff_key($fields, array_filter($rules, static fn (string $key): bool => ! str_contains($key, '.'), ARRAY_FILTER_USE_KEY));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_keys($unknown), __('catalogs.unsupported_field')));
        }

        return Validator::make($fields, $rules)->validate();
    }

    /** @return list<string> */
    public static function name(bool $optional = false): array
    {
        return $optional ? ['bail', 'sometimes', 'required', 'string', 'max:255'] : ['bail', 'required', 'string', 'max:255'];
    }

    /** @return array<string, list<string>> */
    public static function identifiers(string $field): array
    {
        return [
            $field => ['present', 'array', 'list'],
            $field.'.*' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
        ];
    }
}
