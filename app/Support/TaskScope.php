<?php

namespace App\Support;

use App\Models\Expedient;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class TaskScope
{
    public function __construct(private readonly ExpedientLifecycle $lifecycle, private readonly EffectivePermissions $permissions) {}

    public function authorizeTrash(PermissionKey $restore, PermissionKey $forceDelete): void
    {
        Gate::allowIf(function (User $actor) use ($restore, $forceDelete): bool {
            $grants = $this->permissions->for($actor);

            return in_array($restore->value, $grants, true) || in_array($forceDelete->value, $grants, true);
        });
    }

    /** @return array{Task, Expedient} */
    public function read(int $id): array
    {
        $task = Task::query()->whereHas('expedient')->with('expedient')->findOrFail($id);
        $case = $task->expedient ?? throw new IdentityError(404, 'not_found');
        Gate::authorize('view', [$task, $case]);

        return [$task, $case];
    }

    /** Resolve only the parent before acquiring the canonical lock. Call within BusinessWrite. */
    public function lockParent(int $taskId, bool $trashed = false): Expedient
    {
        $query = $trashed ? Task::onlyTrashed() : Task::query();

        return $this->lifecycle->lockParent($query->findOrFail($taskId, ['expedient_id'])->expedient_id);
    }

    /** @return array{Task, Expedient} */
    public function lockActive(int $id, User $actor): array
    {
        $case = $this->lockParent($id);
        $task = $case->tasks()->lockForUpdate()->findOrFail($id);
        Gate::forUser($actor)->authorize('view', [$task, $case]);

        return [$task, $case];
    }
}
