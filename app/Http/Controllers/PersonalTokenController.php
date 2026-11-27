<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AuthenticateCredentials;
use App\Support\IdentityError;
use App\Support\IdentityInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

final class PersonalTokenController extends Controller
{
    public function store(Request $request, AuthenticateCredentials $credentials): JsonResponse
    {
        $input = IdentityInput::validate($request->all(), IdentityInput::credentials() + [
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $expires = now()->startOfSecond()->addDays(max(1, config()->integer('identity.token_days')));
        $issued = DB::transaction(function () use ($credentials, $input, $expires): NewAccessToken {
            $user = $credentials->verify(Arr::string($input, 'email'), Arr::string($input, 'password'));

            return $user->createToken(Arr::string($input, 'device_name'), ['identity'], $expires);
        }, 3);

        return response()->json(['data' => [
            'token' => $issued->plainTextToken,
            'expires_at' => $expires->toISOString(),
        ]], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            throw new IdentityError(403, 'bearer_required');
        }

        $token->delete();

        return response()->json(['message' => __('identity.token_revoked'), 'code' => 'token_revoked']);
    }
}
