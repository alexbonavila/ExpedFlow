<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserAuthorizationResource;
use App\Models\User;
use App\Support\AuthorizationInput;
use App\Support\RoleAssignments;
use Illuminate\Http\Request;

final class UserAuthorizationController extends Controller
{
    public function show(string $user): UserAuthorizationResource
    {
        return new UserAuthorizationResource(User::query()->findOrFail($user));
    }

    public function update(Request $request, string $user, RoleAssignments $assignments): UserAuthorizationResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = User::query()->findOrFail($user);
        $input = validator(AuthorizationInput::fields($request), [
            'role_ids' => ['bail', 'present', 'array', 'list'],
            'role_ids.*' => ['bail', 'required', 'integer:strict', 'min:1', 'distinct:strict'],
        ])->validate();

        return new UserAuthorizationResource($assignments->replace($actor, $target, $input['role_ids']));
    }
}
