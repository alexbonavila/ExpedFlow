<?php

namespace App\Http\Controllers;

use App\Jobs\SendPasswordRecovery;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\IdentityError;
use App\Support\IdentityInput;
use App\Support\RevokeCredentials;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class PasswordRecoveryController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $input = IdentityInput::validate($request->all(), [
            'email' => IdentityInput::emailRules(),
        ]);

        SendPasswordRecovery::dispatch(Arr::string($input, 'email'));

        return response()->json(['message' => __('identity.recovery_requested'), 'code' => 'recovery_requested']);
    }

    public function reset(Request $request, RevokeCredentials $credentials, AuditRecorder $audit): JsonResponse
    {
        $input = IdentityInput::validate($request->all(), [
            'email' => IdentityInput::emailRules(),
            'token' => ['required', 'string', 'max:255'],
            'password' => [...IdentityInput::newPassword(), 'confirmed'],
        ]);

        $operationId = (string) Str::uuid();
        DB::transaction(function () use ($input, $credentials, $audit, $operationId): void {
            $email = Arr::string($input, 'email');
            User::query()->where('email', $email)->lockForUpdate()->first();
            $status = Password::broker()->reset($input + ['disabled_at' => null], function (User $user, #[\SensitiveParameter] string $password) use ($credentials, $audit, $operationId): void {
                $credentials->replacePassword($user, $password);
                $audit->record(AuditContext::passwordRecovery($user, $operationId), AuditEvent::UserPasswordRecovered, $user, after: ['password' => true], metadata: ['credentials_revoked' => true]);
                event(new PasswordReset($user));
            });

            if ($status !== Password::PASSWORD_RESET) {
                throw new IdentityError(422, 'invalid_reset_token');
            }
        }, 3);

        return response()->json(['message' => __('identity.password_reset'), 'code' => 'password_reset']);
    }
}
