<?php

namespace App\Http\Controllers;

use App\Http\Resources\IdentityResource;
use App\Models\User;
use App\Support\AuthenticateCredentials;
use App\Support\IdentityError;
use App\Support\IdentityInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class SessionController extends Controller
{
    public function login(Request $request, AuthenticateCredentials $credentials): IdentityResource
    {
        $input = IdentityInput::validate($request->all(), IdentityInput::credentials());
        $user = DB::transaction(fn (): User => $credentials->verify(Arr::string($input, 'email'), Arr::string($input, 'password')), 3);

        Auth::guard('web')->login($user);
        $request->session()->put(User::SESSION_VERSION, $user->auth_version);

        return new IdentityResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => __('identity.logged_out'), 'code' => 'logged_out']);
    }

    public function show(Request $request): IdentityResource
    {
        return new IdentityResource($request->user() ?? throw new IdentityError(401, 'unauthenticated'));
    }
}
