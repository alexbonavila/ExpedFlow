<?php

namespace App\Http\Controllers;

use App\Http\Resources\TemplateChecklistResource;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
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
use App\Support\TemplateScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class TemplateChecklistController extends Controller
{
    public function __construct(private readonly BusinessWrite $write, private readonly CaseOrdering $ordering, private readonly AuditRecorder $audit, private readonly CatalogOrder $order) {}

    public function index(string $type, string $template): AnonymousResourceCollection
    {
        $record = $this->viewTemplate($type, $template);

        return TemplateChecklistResource::collection($record->checklistItems()->orderBy('position')->orderBy('id')->get());
    }

    public function show(string $type, string $template, string $item): TemplateChecklistResource
    {
        $record = $this->viewTemplate($type, $template);

        return new TemplateChecklistResource($record->checklistItems()->findOrFail(AuthorizationInput::identifier($item)));
    }

    public function store(Request $request, string $type, string $template): JsonResponse
    {
        $fields = CatalogInput::validate($request, ['name' => CatalogInput::name()]);
        $item = $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor, AuditContext $context) use ($type, $template, $fields): TaskTemplateChecklistItem {
            $record = $this->lockTemplate($actor, $type, $template);
            $items = $record->checklistItems()->orderBy('id')->lockForUpdate()->get(['id', 'position']);

            $item = $record->checklistItems()->forceCreate($fields + [
                'position' => $this->ordering->increment($items->max('position') ?? 0),
            ]);
            if (! $item->exists) {
                throw new RuntimeException('Template creation did not complete.');
            }
            $this->audit->record($context, AuditEvent::TemplateChecklistCreated, $item, after: AuditSubject::TemplateChecklist->snapshot($item));

            return $item;
        });

        return (new TemplateChecklistResource($item))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $type, string $template, string $item): TemplateChecklistResource
    {
        $fields = CatalogInput::validate($request, ['name' => CatalogInput::name()]);
        $updated = $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor, AuditContext $context) use ($type, $template, $item, $fields): TaskTemplateChecklistItem {
            $record = $this->lockTemplate($actor, $type, $template);
            $updated = $record->checklistItems()->lockForUpdate()->findOrFail(AuthorizationInput::identifier($item));
            $before = AuditSubject::TemplateChecklist->snapshot($updated);
            if (! $updated->forceFill($fields)->save()) {
                throw new RuntimeException('Template update did not complete.');
            }
            $this->audit->record($context, AuditEvent::TemplateChecklistUpdated, $updated, $before, AuditSubject::TemplateChecklist->snapshot($updated));

            return $updated;
        });

        return new TemplateChecklistResource($updated);
    }

    public function destroy(Request $request, string $type, string $template, string $item): JsonResponse
    {
        $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor, AuditContext $context) use ($type, $template, $item): void {
            $record = $this->lockTemplate($actor, $type, $template);
            $entry = $record->checklistItems()->lockForUpdate()->findOrFail(AuthorizationInput::identifier($item));
            if ($entry->delete() !== true) {
                throw new RuntimeException('Template checklist deletion did not complete.');
            }
            $this->audit->record($context, AuditEvent::TemplateChecklistDeleted, $entry, AuditSubject::TemplateChecklist->snapshot($entry));
        });

        return CatalogWrite::deleted('template_item_deleted');
    }

    public function reorder(Request $request, string $type, string $template): AnonymousResourceCollection
    {
        $ids = Arr::array(CatalogInput::validate($request, CatalogInput::identifiers('item_ids')), 'item_ids');
        $items = $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor, AuditContext $context) use ($type, $template, $ids): Collection {
            $record = $this->lockTemplate($actor, $type, $template);
            $items = $record->checklistItems()->orderBy('id')->lockForUpdate()->get();
            $this->order->replace($items, $ids, $context, AuditEvent::TemplateChecklistReordered);

            return $items->sortBy('position')->values();
        });

        return TemplateChecklistResource::collection($items);
    }

    private function viewTemplate(string $type, string $template): TaskTemplate
    {
        $parent = TemplateScope::type($type);
        $record = TemplateScope::template($parent, $template);
        Gate::authorize('view', [$record, $parent]);

        return $record;
    }

    private function lockTemplate(User $actor, string $type, string $template): TaskTemplate
    {
        Gate::forUser($actor)->authorize(PermissionKey::ViewExpedientTypes->value);
        $parent = TemplateScope::type($type, lock: true);
        $record = TemplateScope::template($parent, $template, lock: true);
        Gate::forUser($actor)->authorize('manageChecklist', [$record, $parent]);

        return $record;
    }
}
