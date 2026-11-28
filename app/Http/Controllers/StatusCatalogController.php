<?php

namespace App\Http\Controllers;

use App\Http\Resources\StatusResource;
use App\Models\Status;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditSubject;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\CaseOrdering;
use App\Support\CatalogInput;
use App\Support\CatalogOrder;
use App\Support\CatalogWrite;
use App\Support\PermissionKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use RuntimeException;

final class StatusCatalogController extends Controller
{
    public function __construct(private readonly BusinessWrite $write, private readonly CaseOrdering $ordering, private readonly AuditRecorder $audit, private readonly CatalogOrder $order) {}

    public function show(string $status): StatusResource
    {
        return new StatusResource(Status::query()->findOrFail(AuthorizationInput::identifier($status)));
    }

    public function store(Request $request): JsonResponse
    {
        $fields = CatalogInput::validate($request, ['name' => CatalogInput::name()]);
        $status = $this->write->run($request, PermissionKey::ManageStatuses, function (User $actor, AuditContext $context) use ($fields): Status {
            $statuses = Status::query()->orderBy('id')->lockForUpdate()->get(['id', 'position']);
            $record = new Status;
            if (! $record->forceFill($fields + ['position' => $this->ordering->increment($statuses->max('position') ?? 0)])->save()) {
                throw new RuntimeException('Catalog creation did not complete.');
            }
            $this->audit->record($context, AuditEvent::StatusCreated, $record, after: AuditSubject::Status->snapshot($record));

            return $record;
        });

        return (new StatusResource($status))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $status): StatusResource
    {
        $id = AuthorizationInput::identifier($status);
        $fields = CatalogInput::validate($request, ['name' => CatalogInput::name()]);
        $record = $this->write->run($request, PermissionKey::ManageStatuses, function (User $actor, AuditContext $context) use ($id, $fields): Status {
            $record = Status::query()->lockForUpdate()->findOrFail($id);
            $before = AuditSubject::Status->snapshot($record);
            if (! $record->forceFill($fields + ['name_translations' => null])->save()) {
                throw new RuntimeException('Catalog update did not complete.');
            }
            $this->audit->record($context, AuditEvent::StatusUpdated, $record, $before, AuditSubject::Status->snapshot($record));

            return $record;
        });

        return new StatusResource($record);
    }

    public function reorder(Request $request): AnonymousResourceCollection
    {
        $ids = Arr::array(CatalogInput::validate($request, CatalogInput::identifiers('status_ids')), 'status_ids');

        return StatusResource::collection($this->write->run($request, PermissionKey::ManageStatuses, function (User $actor, AuditContext $context) use ($ids): Collection {
            $statuses = Status::query()->orderBy('id')->lockForUpdate()->get();
            $this->order->replace($statuses, $ids, $context, AuditEvent::StatusReordered);

            return $statuses->sortBy([['position', 'asc'], ['id', 'asc']])->values();
        }));
    }

    public function destroy(Request $request, string $status): JsonResponse
    {
        $id = AuthorizationInput::identifier($status);
        $this->write->run($request, PermissionKey::ManageStatuses, function (User $actor, AuditContext $context) use ($id): void {
            $record = Status::query()->lockForUpdate()->findOrFail($id);
            if (CatalogWrite::delete($actor, $record, 'status_referenced')) {
                $this->audit->record($context, AuditEvent::StatusDeleted, $record, AuditSubject::Status->snapshot($record));
            }
        });

        return CatalogWrite::deleted('status_deleted');
    }
}
