<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AccountLifecycle;
use App\Support\IdentityError;
use App\Support\IdentityInput;
use App\Support\RevokeCredentials;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

final class UserManagementController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->orderBy('id')->get());
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function store(Request $request): JsonResponse
    {
        $input = IdentityInput::validate($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'locale' => ['sometimes', 'required', Rule::in(IdentityInput::LOCALES)],
            'password' => IdentityInput::newPassword(),
        ]);
        $input['password'] = Hash::make(Arr::string($input, 'password'));
        $user = $this->save(new User, $input + ['locale' => 'es']);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
    {
        $input = IdentityInput::validate($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255'],
            'locale' => ['sometimes', 'required', Rule::in(IdentityInput::LOCALES)],
        ]);

        $account = DB::transaction(function () use ($user, $input): User {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            if (isset($input['email']) && $input['email'] !== $account->email) {
                Password::broker()->deleteToken($account);
                $account->email_verified_at = null;
            }

            return $this->save($account, $input);
        }, 3);

        return new UserResource($account);
    }

    public function password(Request $request, User $user, RevokeCredentials $credentials): JsonResponse
    {
        $input = IdentityInput::validate($request->all(), [
            'password' => [...IdentityInput::newPassword(), 'confirmed'],
        ]);
        DB::transaction(function () use ($user, $input, $credentials): void {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            $credentials->replacePassword($account, Arr::string($input, 'password'));
        }, 3);

        return response()->json(['message' => __('identity.password_replaced'), 'code' => 'password_replaced']);
    }

    public function disable(Request $request, User $user, AccountLifecycle $lifecycle): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new UserResource($lifecycle->disable($actor, $user));
    }

    public function enable(User $user, AccountLifecycle $lifecycle): UserResource
    {
        return new UserResource($lifecycle->enable($user));
    }

    /** @param array<string, mixed> $attributes */
    private function save(User $user, array $attributes): User
    {
        try {
            $user->forceFill($attributes)->save();
        } catch (UniqueConstraintViolationException) {
            throw new IdentityError(409, 'email_conflict');
        }

        return $user;
    }
}
