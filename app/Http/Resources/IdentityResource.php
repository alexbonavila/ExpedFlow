<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\EffectivePermissions;
use Illuminate\Http\Request;

final class IdentityResource extends UserResource
{
    public function __construct(private readonly User $user)
    {
        parent::__construct($user);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'permissions' => app(EffectivePermissions::class)->for($this->user),
        ];
    }
}
