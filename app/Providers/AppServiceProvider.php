<?php

namespace App\Providers;

use App\Models\User;
use App\Support\EffectivePermissions;
use App\Support\IdentityInput;
use App\Support\PermissionKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(EffectivePermissions $permissions): void
    {
        foreach ([
            PermissionKey::ViewClients, PermissionKey::ManageClients,
            PermissionKey::ViewExpedientTypes, PermissionKey::ManageExpedientTypes,
            PermissionKey::ViewStatuses, PermissionKey::ManageStatuses,
            PermissionKey::ViewTags, PermissionKey::ManageTags,
            PermissionKey::ViewIncidents,
        ] as $permission) {
            Gate::define($permission->value, static fn (User $actor): bool => $permissions->allows($actor, $permission));
        }

        foreach (['identity.credentials', 'identity.recovery'] as $name) {
            RateLimiter::for($name, $this->identityLimits(...));
        }
    }

    /** @return list<Limit> */
    private function identityLimits(Request $request): array
    {
        $email = $request->input('email');
        $identity = is_string($email) ? IdentityInput::email($email) : '';
        $ip = $request->ip() ?? 'unknown';
        $accountAndIp = hash('sha256', serialize([$identity, $ip]));

        return [
            Limit::perMinute(max(1, config()->integer('identity.limits.attempts_per_ip')))->by($ip),
            Limit::perMinute(max(1, config()->integer('identity.limits.attempts_per_identity')))->by($accountAndIp),
        ];
    }
}
