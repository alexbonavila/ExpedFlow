<?php

namespace App\Http\Resources;

use App\Support\AuthorizationCatalog;
use App\Support\PermissionKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** @mixin Role */
final class RoleResource extends JsonResource
{
    /** @return array{id: int|string, name: string, permissions: list<string>} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permissions' => array_values($this->permissions
                ->where('guard_name', AuthorizationCatalog::GUARD)
                ->map(fn (Permission $permission): string => $permission->name)
                ->filter(fn (string $name): bool => PermissionKey::tryFrom($name) !== null)
                ->sort()
                ->all()),
        ];
    }
}
