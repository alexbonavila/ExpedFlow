<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\EffectivePermissions;
use App\Support\PermissionKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class RequirePermission
{
    public function __construct(private readonly EffectivePermissions $permissions) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $key): Response
    {
        Gate::allowIf(fn (User $user): bool => $this->permissions->allows($user, PermissionKey::from($key)));

        return $next($request);
    }
}
