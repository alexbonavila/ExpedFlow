<?php

namespace Tests\Support;

use App\Models\User;

trait AuthorizationHttp
{
    use IdentityHttp;

    protected function authorizationAdmin(string $locale = 'en'): User
    {
        return User::factory()->create(['locale' => $locale])->assignRole(User::ADMIN_ROLE);
    }

    /** @return array<string, string> */
    protected function authorizationBearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('Authorization fixture', ['*'])->plainTextToken];
    }
}
