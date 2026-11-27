<?php

namespace App\Http\Controllers;

use App\Http\Resources\TemplateChecklistResource;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
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

final class TemplateChecklistController extends Controller
{
    public function __construct(private readonly BusinessWrite $write, private readonly CaseOrdering $ordering) {}

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
        $item = $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor) use ($type, $template, $fields): TaskTemplateChecklistItem {
            $record = $this->lockTemplate($actor, $type, $template);
            $items = $record->checklistItems()->orderBy('id')->lockForUpdate()->get(['id', 'position']);

            return $record->checklistItems()->forceCreate($fields + [
                'position' => $this->ordering->increment($items->max('position') ?? 0),
            ]);
        });

        return (new TemplateChecklistResource($item))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $type, string $template, string $item): TemplateChecklistResource
    {
        $fields = CatalogInput::validate($request, ['name' => CatalogInput::name()]);
        $updated = $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor) use ($type, $template, $item, $fields): TaskTemplateChecklistItem {
            $record = $this->lockTemplate($actor, $type, $template);
            $updated = $record->checklistItems()->lockForUpdate()->findOrFail(AuthorizationInput::identifier($item));
            $updated->forceFill($fields)->save();

            return $updated;
        });

        return new TemplateChecklistResource($updated);
    }

    public function destroy(Request $request, string $type, string $template, string $item): JsonResponse
    {
        $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor) use ($type, $template, $item): void {
            $record = $this->lockTemplate($actor, $type, $template);
            $record->checklistItems()->lockForUpdate()->findOrFail(AuthorizationInput::identifier($item))->delete();
        });

        return CatalogWrite::deleted('template_item_deleted');
    }

    public function reorder(Request $request, string $type, string $template): AnonymousResourceCollection
    {
        $ids = Arr::array(CatalogInput::validate($request, CatalogInput::identifiers('item_ids')), 'item_ids');
        $items = $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor) use ($type, $template, $ids): Collection {
            $record = $this->lockTemplate($actor, $type, $template);
            $items = $record->checklistItems()->orderBy('id')->lockForUpdate()->get();
            CatalogOrder::replace($items, $ids);

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
