<?php

namespace App\Support;

use App\Models\Expedient;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditTaskDescendants;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class ExpedientLifecycle
{
    public function __construct(
        private readonly CaseOrdering $ordering,
        private readonly AuditRecorder $audit,
        private readonly AuditTaskDescendants $descendants,
    ) {}

    /** Acquire the case row before reading or mutating children in the write transaction. */
    public function lockParent(int $id, bool $trashed = false): Expedient
    {
        $query = $trashed ? Expedient::onlyTrashed() : Expedient::query();

        return $query->lockForUpdate()->findOrFail($id);
    }

    public function delete(User $actor, int $id, ?AuditContext $context = null): void
    {
        $case = $this->lockParent($id);
        Gate::forUser($actor)->authorize('delete', $case);
        $tasks = $case->tasks()->orderBy('id')->lockForUpdate()->pluck('id');
        $case->tasks()->whereKey($tasks->all())->update(['deleted_at' => now(), 'deleted_by_expedient' => true]);
        if (! $case->delete()) {
            throw new RuntimeException('Case deletion did not complete.');
        }
        $context ??= AuditContext::authenticated($actor);
        $this->audit->record($context, AuditEvent::CaseDeleted, $case, ['state' => 'active'], ['state' => 'trashed']);
        foreach ($tasks->chunk(500) as $chunk) {
            $this->audit->record($context, AuditEvent::CaseTasksDeleted, $case, metadata: ['task_ids' => $chunk->values()->all()]);
        }
    }

    public function restore(User $actor, int $id, AuditContext $context): Expedient
    {
        $case = $this->lockParent($id, trashed: true);
        $gate = Gate::forUser($actor);
        $gate->authorize('restore', $case);
        $this->ordering->lockStatuses($case->status_id);
        $tasks = $case->tasks()->withTrashed()->orderBy('id')->lockForUpdate()->get();
        $occupied = [];
        $restoring = [];
        $collisions = [];

        foreach ($tasks as $task) {
            if (! $task->trashed()) {
                $occupied[$task->status_id][$task->position] = true;
            }
        }
        foreach ($tasks as $task) {
            if (! $task->deleted_by_expedient) {
                continue;
            }

            $gate->authorize('restoreCascadeTask', [$case, $task]);
            $restoring[] = $task;
            if (isset($occupied[$task->status_id][$task->position])) {
                $collisions[] = $task;
            } else {
                $occupied[$task->status_id][$task->position] = true;
            }
        }
        foreach ($collisions as $task) {
            $position = 1;
            while (isset($occupied[$task->status_id][$position])) {
                $position++;
            }
            $occupied[$task->status_id][$position] = true;
            $task->forceFill(['position' => $position]);
        }
        foreach ($restoring as $task) {
            $previous = $task->getRawOriginal('position');
            if (! $task->forceFill(['deleted_by_expedient' => false])->restore()) {
                throw new RuntimeException('Cascaded task restoration did not complete.');
            }
            $this->audit->record($context, AuditEvent::TaskRestored, $task,
                ['state' => 'trashed', 'position' => $previous],
                ['state' => 'active', 'position' => $task->position], $case);
        }
        if (! $case->restore()) {
            throw new RuntimeException('Case restoration did not complete.');
        }
        $this->audit->record($context, AuditEvent::CaseRestored, $case, ['state' => 'trashed'], ['state' => 'active']);

        return $case;
    }

    public function forceDelete(User $actor, int $id, AuditContext $context): void
    {
        $case = $this->lockParent($id, trashed: true);
        Gate::forUser($actor)->authorize('forceDelete', $case);
        $this->descendants->record($context, $case);
        if (! $case->forceDelete()) {
            throw new RuntimeException('Permanent case deletion did not complete.');
        }
        $this->audit->record($context, AuditEvent::CaseForceDeleted, $case, ['state' => 'trashed'], ['state' => 'deleted']);
    }
}
