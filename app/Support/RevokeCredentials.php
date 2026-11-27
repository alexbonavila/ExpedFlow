<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

final class RevokeCredentials
{
    /** The caller must hold the account lock for the whole transaction. */
    public function replacePassword(User $user, #[\SensitiveParameter] string $password): void
    {
        $user->forceFill(['password' => Hash::make($password)]);
        $this->revoke($user);
    }

    /** The caller must hold the account lock for the whole transaction. */
    public function revoke(User $user): void
    {
        $user->forceFill([
            'auth_version' => $user->auth_version + 1,
            'remember_token' => null,
        ])->save();

        Password::broker()->deleteToken($user);
        $user->tokens()->delete();
        DB::table(config()->string('session.table'))->where('user_id', $user->id)->delete();
    }
}
