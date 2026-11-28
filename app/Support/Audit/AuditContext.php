<?php

namespace App\Support\Audit;

use App\Models\User;
use Illuminate\Support\Str;

final readonly class AuditContext
{
    private function __construct(public string $origin, public ?int $actorId, public string $operationId) {}

    public static function authenticated(User $actor, ?string $operationId = null): self
    {
        return new self('authenticated', $actor->id, $operationId ?? (string) Str::uuid());
    }

    public static function passwordRecovery(User $account, ?string $operationId = null): self
    {
        return new self('password_recovery', $account->id, $operationId ?? (string) Str::uuid());
    }

    public static function bootstrap(): self
    {
        return new self('bootstrap', null, (string) Str::uuid());
    }
}
