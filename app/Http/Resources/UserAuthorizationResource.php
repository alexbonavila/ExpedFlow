<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\AuthorizationCatalog;
use App\Support\EffectivePermissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

final class UserAuthorizationResource extends JsonResource
{
    public function __construct(private readonly User $user)
    {
        parent::__construct($user);
    }

    /** @return array{roles: list<array{id: int|string, name: string}>, permissions: list<string>} */
    public function toArray(Request $request): array
    {
        $memberships = $this->user->roles()->getQuery()->select('roles.id');
        $roles = AuthorizationCatalog::roles()->whereIn('id', $memberships)->orderBy('id')->get();

        return [
            'roles' => array_values($roles->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])->all()),
            'permissions' => app(EffectivePermissions::class)->for($this->user),
        ];
    }
}
