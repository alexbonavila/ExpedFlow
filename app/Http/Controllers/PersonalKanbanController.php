<?php

namespace App\Http\Controllers;

use App\Http\Resources\StatusResource;
use App\Models\Expedient;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\CatalogInput;
use App\Support\IdentityError;
use App\Support\PermissionKey;
use App\Support\PersonalKanban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

final class PersonalKanbanController extends Controller
{
    public function __construct(private readonly BusinessWrite $write, private readonly PersonalKanban $kanban) {}

    public function board(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Expedient::class);
        $columns = $this->kanban->board($request->user() ?? throw new IdentityError(401, 'unauthenticated'));
        $data = [];
        foreach ($columns as $column) {
            $data[] = (new StatusResource($column['status']))->toArray($request) + ['expedients' => $column['expedients']];
        }

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $expedient): JsonResponse
    {
        $actor = $request->user() ?? throw new IdentityError(401, 'unauthenticated');

        return response()->json(['data' => $this->kanban->preference($actor, AuthorizationInput::identifier($expedient))]);
    }

    public function update(Request $request, string $expedient): JsonResponse
    {
        $id = AuthorizationInput::identifier($expedient);
        $hidden = Arr::boolean(CatalogInput::validate($request, ['hidden' => ['required', 'boolean:strict']]), 'hidden');
        $data = $this->write->run($request, PermissionKey::ManagePersonalKanban,
            fn (User $actor): array => $this->kanban->setHidden($actor, $id, $hidden));

        return response()->json(['data' => $data]);
    }

    public function move(Request $request, string $expedient): JsonResponse
    {
        $id = AuthorizationInput::identifier($expedient);
        $fields = CatalogInput::validate($request, [
            'status_id' => ['required', 'integer:strict', 'min:1'],
            'position' => ['sometimes', 'required', 'integer:strict', 'min:1'],
        ]);
        $status = Arr::integer($fields, 'status_id');
        $position = isset($fields['position']) ? Arr::integer($fields, 'position') : null;
        $data = $this->write->run($request, PermissionKey::ManagePersonalKanban,
            fn (User $actor, AuditContext $context): array => $this->kanban->move($actor, $id, $status, $position, $context));

        return response()->json(['data' => $data]);
    }
}
