<?php

namespace App\Http\Controllers;

use App\Jobs\SendPasswordRecovery;
use App\Models\User;
use App\Support\IdentityError;
use App\Support\IdentityInput;
use App\Support\RevokeCredentials;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

final class PasswordRecoveryController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $input = IdentityInput::validate($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        SendPasswordRecovery::dispatch(Arr::string($input, 'email'));

        return response()->json(['message' => __('identity.recovery_requested'), 'code' => 'recovery_requested']);
    }

    public function reset(Request $request, RevokeCredentials $credentials): JsonResponse
    {
        $input = IdentityInput::validate($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => [...IdentityInput::newPassword(), 'confirmed'],
        ]);

        DB::transaction(function () use ($input, $credentials): void {
            $email = Arr::string($input, 'email');
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($user === null || $user->disabled_at !== null) {
                throw new IdentityError(422, 'invalid_reset_token');
            }

            $status = Password::broker()->reset($input, function (User $user, #[\SensitiveParameter] string $password) use ($credentials): void {
                $credentials->replacePassword($user, $password);
                event(new PasswordReset($user));
            });

            if ($status !== Password::PASSWORD_RESET) {
                throw new IdentityError(422, 'invalid_reset_token');
            }
        }, 3);

        return response()->json(['message' => __('identity.password_reset'), 'code' => 'password_reset']);
    }
}
