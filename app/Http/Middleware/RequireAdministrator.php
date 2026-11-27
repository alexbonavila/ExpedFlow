<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdministrator
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        Gate::allowIf(fn (User $user): bool => $user->hasRole(User::ADMIN_ROLE, 'web'));

        return $next($request);
    }
}
