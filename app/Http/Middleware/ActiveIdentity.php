<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\IdentityError;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

final class ActiveIdentity
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->disabled_at !== null) {
            throw new IdentityError(401, 'unauthenticated');
        }

        if ($user->currentAccessToken() instanceof TransientToken
            && (! $request->hasSession() || $request->session()->get(User::SESSION_VERSION) !== $user->auth_version)) {
            throw new IdentityError(401, 'unauthenticated');
        }

        $request->attributes->set('identity.locale', $user->locale);
        app()->setLocale($user->locale);

        return $next($request);
    }
}
