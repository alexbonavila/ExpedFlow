<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExpedientTypeResource;
use App\Models\ExpedientType;
use App\Models\User;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\CatalogInput;
use App\Support\CatalogWrite;
use App\Support\IdentityError;
use App\Support\PermissionKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExpedientTypeCatalogController extends Controller
{
    public function __construct(private readonly BusinessWrite $write) {}

    public function show(string $type): ExpedientTypeResource
    {
        return new ExpedientTypeResource(ExpedientType::query()->findOrFail(AuthorizationInput::identifier($type)));
    }

    public function store(Request $request): JsonResponse
    {
        $fields = CatalogInput::validate($request, [
            'name' => CatalogInput::name(),
            'code' => ['bail', 'required', 'string', 'regex:/\A[0-9]{2}\z/'],
        ]);

        $type = $this->write->run($request, PermissionKey::ManageExpedientTypes, function () use ($fields): ExpedientType {
            try {
                $type = new ExpedientType;
                $type->forceFill($fields)->save();

                return $type;
            } catch (UniqueConstraintViolationException) {
                throw new IdentityError(409, 'type_code_conflict', 'catalogs');
            }
        });

        return (new ExpedientTypeResource($type))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $type): ExpedientTypeResource
    {
        $id = AuthorizationInput::identifier($type);
        $fields = CatalogInput::validate($request, ['name' => CatalogInput::name()]);
        $record = $this->write->run($request, PermissionKey::ManageExpedientTypes, function () use ($id, $fields): ExpedientType {
            $record = ExpedientType::query()->lockForUpdate()->findOrFail($id);
            $record->forceFill($fields)->save();

            return $record;
        });

        return new ExpedientTypeResource($record);
    }

    public function destroy(Request $request, string $type): JsonResponse
    {
        $id = AuthorizationInput::identifier($type);
        $this->write->run($request, PermissionKey::ManageExpedientTypes, function (User $actor) use ($id): void {
            CatalogWrite::delete($actor, ExpedientType::query()->lockForUpdate()->findOrFail($id), 'type_referenced');
        });

        return CatalogWrite::deleted('type_deleted');
    }
}
