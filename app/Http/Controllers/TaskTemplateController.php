<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskTemplateResource;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Support\BusinessWrite;
use App\Support\CaseOrdering;
use App\Support\CatalogInput;
use App\Support\CatalogOrder;
use App\Support\CatalogWrite;
use App\Support\PermissionKey;
use App\Support\TemplateScope;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class TaskTemplateController extends Controller
{
    public function __construct(private readonly BusinessWrite $write, private readonly CaseOrdering $ordering) {}

    public function index(string $type): AnonymousResourceCollection
    {
        $parent = TemplateScope::type($type);
        Gate::authorize('viewAny', [TaskTemplate::class, $parent]);

        return TaskTemplateResource::collection($parent->templates()->orderBy('position')->orderBy('id')->get());
    }

    public function show(string $type, string $template): TaskTemplateResource
    {
        $parent = TemplateScope::type($type);
        $record = TemplateScope::template($parent, $template);
        Gate::authorize('view', [$record, $parent]);

        return new TaskTemplateResource($record);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $fields = $this->fields($request);
        $record = $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor) use ($type, $fields): TaskTemplate {
            Gate::forUser($actor)->authorize(PermissionKey::ViewExpedientTypes->value);
            $parent = TemplateScope::type($type, lock: true);
            Gate::forUser($actor)->authorize('create', [TaskTemplate::class, $parent]);
            $templates = $parent->templates()->orderBy('id')->lockForUpdate()->get(['id', 'position']);

            return $parent->templates()->forceCreate($fields + [
                'description' => null,
                'position' => $this->ordering->increment($templates->max('position') ?? 0),
            ]);
        });

        return (new TaskTemplateResource($record))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $type, string $template): TaskTemplateResource
    {
        $fields = $this->fields($request, partial: true);
        $record = $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor) use ($type, $template, $fields): TaskTemplate {
            Gate::forUser($actor)->authorize(PermissionKey::ViewExpedientTypes->value);
            $parent = TemplateScope::type($type, lock: true);
            $record = TemplateScope::template($parent, $template, lock: true);
            Gate::forUser($actor)->authorize('update', [$record, $parent]);
            $record->forceFill($fields)->save();

            return $record;
        });

        return new TaskTemplateResource($record);
    }

    public function destroy(Request $request, string $type, string $template): JsonResponse
    {
        $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor) use ($type, $template): void {
            Gate::forUser($actor)->authorize(PermissionKey::ViewExpedientTypes->value);
            $parent = TemplateScope::type($type, lock: true);
            $record = TemplateScope::template($parent, $template, lock: true);
            Gate::forUser($actor)->authorize('delete', [$record, $parent]);
            $record->delete();
        });

        return CatalogWrite::deleted('template_deleted');
    }

    public function reorder(Request $request, string $type): AnonymousResourceCollection
    {
        $ids = Arr::array(CatalogInput::validate($request, CatalogInput::identifiers('template_ids')), 'template_ids');
        $records = $this->write->run($request, PermissionKey::ManageTaskTemplates, function (User $actor) use ($type, $ids): Collection {
            Gate::forUser($actor)->authorize(PermissionKey::ViewExpedientTypes->value);
            $parent = TemplateScope::type($type, lock: true);
            Gate::forUser($actor)->authorize('create', [TaskTemplate::class, $parent]);
            $records = $parent->templates()->orderBy('id')->lockForUpdate()->get();
            CatalogOrder::replace($records, $ids);

            return $records->sortBy('position')->values();
        });

        return TaskTemplateResource::collection($records);
    }

    /** @return array<string, mixed> */
    private function fields(Request $request, bool $partial = false): array
    {
        return CatalogInput::validate($request, [
            'name' => CatalogInput::name($partial),
            'description' => ['bail', 'nullable', 'string', function (string $attribute, string $value, Closure $fail): void {
                if (strlen($value) > 65_535) {
                    $fail('identity.case_description_too_long')->translate();
                }
            }],
        ]);
    }
}
