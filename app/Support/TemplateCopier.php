<?php

namespace App\Support;

use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditSubject;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class TemplateCopier
{
    public function __construct(private readonly EffectivePermissions $permissions, private readonly AuditRecorder $audit) {}

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
    public function copy(User $actor, Expedient $case, ExpedientType $type, int $status, AuditContext $context): void
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('viewAny', [TaskTemplate::class, $type]);
        $gate->authorize('create', [Task::class, $case]);

        $templates = $type->templates()->orderBy('id')->sharedLock()->get();
        $items = TaskTemplateChecklistItem::query()->whereIn('task_template_id', $templates->modelKeys())
            ->orderBy('id')->sharedLock()->get()
            ->sortBy([['position', 'asc'], ['id', 'asc']])->groupBy('task_template_id');

        $checklistCount = 0;
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

            $this->audit->record($context, AuditEvent::TaskCopied, $task,
                after: AuditSubject::Task->snapshot($task) + ['state' => 'active'], case: $case,
                metadata: ['template_id' => $template->id]);

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
                $checklistCount++;
                $this->audit->record($context, AuditEvent::ChecklistCopied, $copy,
                    after: AuditSubject::Checklist->snapshot($copy), case: $case,
                    metadata: ['task_id' => $task->id, 'source_item_id' => $item->id]);
            }
        }
        if ($templates->isNotEmpty()) {
            $this->audit->record($context, AuditEvent::CaseTemplatesCopied, $case, metadata: [
                'status_id' => $status, 'case_type_id' => $type->id,
                'task_count' => $templates->count(), 'checklist_count' => $checklistCount,
            ]);
        }
    }
}
