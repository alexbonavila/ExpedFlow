<?php

namespace App\Http\Middleware;

use App\Support\IdentityError;
use App\Support\IdentityInput;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class IdentityResponse
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/*')) {
            return $next($request);
        }

        $previous = app()->getLocale();
        $locale = $request->getLanguages() === []
            ? $previous
            : $request->getPreferredLanguage(IdentityInput::LOCALES);

        app()->setLocale(in_array($locale, IdentityInput::LOCALES, true) ? $locale : 'en');

        try {
            if (! $request->isSecure() && ! app()->environment('testing')) {
                throw new IdentityError(403, 'https_required');
            }

            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } finally {
            app()->setLocale($previous);
        }
    }
}
