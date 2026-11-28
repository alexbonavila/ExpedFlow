<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class IdentityError extends HttpException
{
    public function __construct(int $status, public readonly string $errorCode, private readonly string $translationGroup = 'identity')
    {
        parent::__construct($status);
    }

    public static function response(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v1', 'api/v1/*')) {
            return null;
        }

        $status = match (true) {
            $exception instanceof AuthenticationException => 401,
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };

        $code = $exception instanceof self ? $exception->errorCode : match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            413 => 'payload_too_large',
            419 => 'csrf_mismatch',
            422 => 'validation_failed',
            429 => 'rate_limited',
            503 => 'unavailable',
            default => 'server_error',
        };

        $locale = $request->attributes->get('identity.locale');
        $group = $exception instanceof self ? $exception->translationGroup : 'identity';
        $body = ['message' => __($group.'.'.$code, locale: is_string($locale) ? $locale : null), 'code' => $code];
        if ($exception instanceof ValidationException) {
            $body['errors'] = $exception->errors();
        }

        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        return response()->json($body, $status, ['Cache-Control' => 'no-store'] + $headers);
    }
}
