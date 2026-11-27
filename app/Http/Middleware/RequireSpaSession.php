<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\IdentityError;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class RequireSpaSession
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $request->hasSession() || ($user instanceof User && $user->currentAccessToken() instanceof PersonalAccessToken)) {
            throw new IdentityError(403, 'session_required');
        }

        return $next($request);
    }
}
