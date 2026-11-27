<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AccountLifecycle;
use App\Support\AdministrativeWrite;
use App\Support\IdentityError;
use App\Support\IdentityInput;
use App\Support\PermissionKey;
use App\Support\RevokeCredentials;
use App\Support\RoleAssignments;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

final class UserManagementController extends Controller
{
    public function __construct(private readonly AdministrativeWrite $write) {}

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->orderBy('id')->get());
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function store(Request $request, RoleAssignments $assignments): JsonResponse
    {
        $input = IdentityInput::validate($request->all(), [
            'name' => IdentityInput::nameRules(),
            'email' => IdentityInput::emailRules(),
            'locale' => ['sometimes', ...IdentityInput::localeRules()],
            'password' => IdentityInput::newPassword(),
        ]);
        $input['password'] = Hash::make(Arr::string($input, 'password'));
        /** @var User $actor */
        $actor = $request->user();
        $user = $this->write->run($actor, PermissionKey::ManageUsers, function () use ($input, $assignments): User {
            $account = $this->save(new User, $input + ['locale' => 'es']);
            $assignments->assignDefault($account);

            return $account;
        });

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
    {
        $input = IdentityInput::validate($request->all(), [
            'name' => ['sometimes', ...IdentityInput::nameRules()],
            'email' => ['sometimes', ...IdentityInput::emailRules()],
            'locale' => ['sometimes', ...IdentityInput::localeRules()],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $account = $this->write->run($actor, PermissionKey::ManageUsers, function (User $account) use ($input): User {
            if (isset($input['email']) && $input['email'] !== $account->email) {
                Password::deleteToken($account);
                $account->email_verified_at = null;
            }

            return $this->save($account, $input);
        }, $user);

        return new UserResource($account);
    }

    public function password(Request $request, User $user, RevokeCredentials $credentials): JsonResponse
    {
        $input = IdentityInput::validate($request->all(), [
            'password' => [...IdentityInput::newPassword(), 'confirmed'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $this->write->run($actor, PermissionKey::ManageUsers, function (User $account) use ($input, $credentials): void {
            $credentials->replacePassword($account, Arr::string($input, 'password'));
        }, $user);

        return response()->json(['message' => __('identity.password_replaced'), 'code' => 'password_replaced']);
    }

    public function disable(Request $request, User $user, AccountLifecycle $lifecycle): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new UserResource($lifecycle->disable($actor, $user));
    }

    public function enable(Request $request, User $user, AccountLifecycle $lifecycle): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new UserResource($lifecycle->enable($actor, $user));
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
