<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;

final class AuthenticateCredentials
{
    public function __construct(private readonly Timebox $timebox) {}

    /**
     * Call within a transaction so credential rotation and token issuance share the account lock.
     */
    public function verify(string $email, #[\SensitiveParameter] string $password): User
    {
        return $this->timebox->call(function () use ($email, $password): User {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            $valid = Hash::check($password, $user->password ?? '');

            if (! $valid || $user === null || $user->disabled_at !== null) {
                throw new IdentityError(401, 'invalid_credentials');
            }

            if (Hash::needsRehash($user->password)) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }

            return $user;
        }, 200_000);
    }
}
