<?php

namespace App\Support;

use Illuminate\Http\Request;

final class AuthorizationInput
{
    /** @return array<string, mixed> */
    public static function fields(Request $request): array
    {
        $payload = json_decode($request->getContent());

        return is_object($payload) ? get_object_vars($payload) : [];
    }
}
