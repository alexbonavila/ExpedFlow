<?php

namespace App\Support;

use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class TemplateCopier
{
    public function __construct(private readonly EffectivePermissions $permissions) {}

    public function authorize(User $actor): void
    {
        $required = [
            PermissionKey::ViewExpedients->value,
            PermissionKey::CreateTasks->value,
            PermissionKey::ViewExpedientTypes->value,
            PermissionKey::ViewTaskTemplates->value,
        ];

        Gate::forUser($actor)->allowIf(fn (User $user): bool => array_diff($required, $this->permissions->for($user)) === []);
    }

    /** The caller holds the selected status and type locks in the case creation transaction. */
    public function copy(User $actor, Expedient $case, ExpedientType $type, int $status): void
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('viewAny', [TaskTemplate::class, $type]);
        $gate->authorize('create', [Task::class, $case]);

        $templates = $type->templates()->orderBy('id')->sharedLock()->get();
        $items = TaskTemplateChecklistItem::query()->whereIn('task_template_id', $templates->modelKeys())
            ->orderBy('id')->sharedLock()->get()
            ->sortBy([['position', 'asc'], ['id', 'asc']])->groupBy('task_template_id');

        foreach ($templates->sortBy([['position', 'asc'], ['id', 'asc']])->values() as $index => $template) {
            $task = $case->tasks()->forceCreate([
                'name' => $template->name,
                'description' => $template->description,
                'status_id' => $status,
                'position' => $index + 1,
            ]);
            if (! $task->exists) {
                throw new RuntimeException('Copied task persistence did not complete.');
            }

            $checklist = $items->get($template->id);
            if ($checklist === null) {
                continue;
            }

            $gate->authorize('manage', [TaskChecklistItem::class, $task, $case]);
            foreach ($checklist->values() as $itemIndex => $item) {
                $copy = $task->checklistItems()->forceCreate([
                    'name' => $item->name,
                    'position' => $itemIndex + 1,
                    'completed' => false,
                ]);
                if (! $copy->exists) {
                    throw new RuntimeException('Copied checklist item persistence did not complete.');
                }
            }
        }
    }
}
