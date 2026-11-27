<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

final class BusinessWrite
{
    public function __construct(private readonly EffectivePermissions $permissions) {}

    /**
     * @template TResult
     *
     * @param  Closure(User): TResult  $operation
     * @return TResult
     */
    public function run(Request $request, PermissionKey $permission, Closure $operation): mixed
    {
        $actor = $request->user() ?? throw new IdentityError(401, 'unauthenticated');

        return DB::transaction(function () use ($request, $actor, $permission, $operation): mixed {
            AuthorizationCatalog::lockAdministratorRole();
            $account = User::query()->lockForUpdate()->find($actor->id);

            if ($account === null || $account->disabled_at !== null || $account->auth_version !== $actor->auth_version
                || ! $this->credentialIsCurrent($request, $actor, $account)) {
                throw new IdentityError(401, 'unauthenticated');
            }

            Gate::forUser($account)->allowIf(fn (User $user): bool => $this->permissions->allows($user, $permission));

            return $operation($account);
        }, 3);
    }

    private function credentialIsCurrent(Request $request, User $actor, User $account): bool
    {
        $credential = $actor->currentAccessToken();
        if ($credential instanceof PersonalAccessToken) {
            $current = $account->tokens()->lockForUpdate()->find($credential->id);
            $maximumAge = config('sanctum.expiration');

            return $current !== null && hash_equals($current->token, $credential->token)
                && (! $maximumAge || $current->created_at?->gt(now()->subMinutes($maximumAge)))
                && ! $current->expires_at?->isPast();
        }

        if (! $credential instanceof TransientToken || ! $request->hasSession()
            || config('session.driver') !== 'database'
            || (config('session.connection') ?? config('database.default')) !== config('database.default')) {
            return false;
        }

        return $request->session()->get(User::SESSION_VERSION) === $account->auth_version
            && DB::table(config()->string('session.table'))->where('id', $request->session()->getId())
                ->where('user_id', $account->id)
                ->where('last_activity', '>=', now()->subMinutes(config()->integer('session.lifetime'))->timestamp)
                ->lockForUpdate()->first() !== null;
    }
}
