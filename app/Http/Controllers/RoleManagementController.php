<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Support\AuthorizationInput;
use App\Support\PermissionKey;
use App\Support\RoleManagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class RoleManagementController extends Controller
{
    public function __construct(private readonly RoleManagement $roles) {}

    public function permissions(): JsonResponse
    {
        $catalog = array_map(static function (PermissionKey $key): array {
            $group = $key->group();
            $label = __('permissions.actions.'.$key->action(), ['resource' => __('permissions.groups.'.$group)]);

            return [
                'key' => $key->value,
                'group' => $group,
                'label' => $label,
                'description' => __('permissions.description', ['action' => $label]),
                'restricted' => $key->restricted(),
            ];
        }, PermissionKey::cases());

        return response()->json(['data' => $catalog]);
    }

    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection($this->roles->all());
    }

    public function show(string $role): RoleResource
    {
        return new RoleResource($this->roles->find(AuthorizationInput::identifier($role)));
    }

    public function store(Request $request): JsonResponse
    {
        $name = $this->name($request);
        /** @var User $actor */
        $actor = $request->user();
        $role = $this->roles->create($actor, $name, $this->permissionKeys($request));

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $role): RoleResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new RoleResource($this->roles->rename($actor, AuthorizationInput::identifier($role), $this->name($request)));
    }

    public function replacePermissions(Request $request, string $role): RoleResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new RoleResource($this->roles->replacePermissions($actor, AuthorizationInput::identifier($role), $this->permissionKeys($request)));
    }

    public function destroy(Request $request, string $role): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->roles->delete($actor, AuthorizationInput::identifier($role));

        return response()->json(['message' => __('identity.role_deleted'), 'code' => 'role_deleted']);
    }

    private function name(Request $request): string
    {
        $name = AuthorizationInput::fields($request)['name'] ?? null;

        return validator(['name' => is_string($name) ? Str::trim($name) : $name], [
            'name' => ['bail', 'required', 'string', 'max:125'],
        ])->validate()['name'];
    }

    /** @return list<string> */
    private function permissionKeys(Request $request): array
    {
        return validator(AuthorizationInput::fields($request), [
            'permissions' => ['bail', 'present', 'array', 'list', 'max:'.count(PermissionKey::cases())],
            'permissions.*' => ['bail', 'required', 'string', 'distinct:strict', Rule::enum(PermissionKey::class)],
        ])->validate()['permissions'];
    }
}
