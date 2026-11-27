<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class IdentityResource extends UserResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'permissions' => $this->getAllPermissions()->pluck('name')->unique()->sort()->values()->all(),
        ];
    }
}
