<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\AuthenticateCredentials;
use App\Support\IdentityError;
use App\Support\IdentityInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

final class PersonalTokenController extends Controller
{
    public function store(Request $request, AuthenticateCredentials $credentials, AuditRecorder $audit): JsonResponse
    {
        $input = IdentityInput::validate($request->all(), IdentityInput::credentials() + [
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $expires = now()->startOfSecond()->addDays(max(1, config()->integer('identity.token_days')));
        $operationId = (string) Str::uuid();
        $issued = DB::transaction(function () use ($credentials, $input, $expires, $audit, $operationId): NewAccessToken {
            $user = $credentials->verify(Arr::string($input, 'email'), Arr::string($input, 'password'));

            $issued = $user->createToken(Arr::string($input, 'device_name'), ['identity'], $expires);
            if (! $issued->accessToken->exists) {
                throw new RuntimeException('Credential persistence did not complete.');
            }
            $audit->record(AuditContext::authenticated($user, $operationId), AuditEvent::UserTokenIssued, $user);

            return $issued;
        }, 3);

        return response()->json(['data' => [
            'token' => $issued->plainTextToken,
            'expires_at' => $expires->toISOString(),
        ]], 201);
    }

    public function destroy(Request $request, AuditRecorder $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            throw new IdentityError(403, 'bearer_required');
        }

        $context = AuditContext::authenticated($user);
        DB::transaction(function () use ($user, $token, $context, $audit): void {
            $account = User::query()->lockForUpdate()->find($user->id);
            if ($account !== null && $account->tokens()->whereKey($token->id)->delete() === 1) {
                $audit->record($context, AuditEvent::UserTokenRevoked, $account);
            }
        }, 3);

        return response()->json(['message' => __('identity.token_revoked'), 'code' => 'token_revoked']);
    }
}
