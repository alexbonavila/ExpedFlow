<?php

namespace App\Support;

use Illuminate\Http\Request;

final class AuthorizationInput
{
    public static function identifier(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? throw new IdentityError(404, 'not_found') : $id;
    }

    /** @return array<string, mixed> */
    public static function fields(Request $request): array
    {
        $payload = json_decode($request->getContent());

        return is_object($payload) ? get_object_vars($payload) : [];
    }
}
