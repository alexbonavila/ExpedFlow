<?php

namespace App\Providers;

use App\Models\User;
use App\Support\IdentityInput;
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
    public function boot(): void
    {
        Gate::define('manage-accounts', fn (User $user): bool => $user->hasRole(User::ADMIN_ROLE, 'web'));

        foreach (['identity.credentials', 'identity.recovery'] as $name) {
            RateLimiter::for($name, $this->identityLimits(...));
        }
    }

    /** @return list<Limit> */
    private function identityLimits(Request $request): array
    {
        $email = $request->input('email');
        $identity = is_string($email) ? IdentityInput::email($email) : '';
        $ip = (string) $request->ip();

        return [
            Limit::perMinute(max(1, config()->integer('identity.limits.attempts_per_ip')))->by('ip:'.$ip),
            Limit::perMinute(max(1, config()->integer('identity.limits.attempts_per_identity')))->by('identity:'.hash('sha256', $identity).'@'.$ip),
        ];
    }
}
