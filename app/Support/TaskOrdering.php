<?php

namespace App\Support;

use App\Models\Expedient;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class TaskOrdering
{
    public function __construct(private readonly CaseOrdering $statuses) {}

    /** Acquire catalog locks before the ordered task locks, inside the parent transaction. */
    public function lockStatuses(int ...$ids): void
    {
        $this->statuses->lockStatuses(...$ids);
    }

    /** @return Collection<int, Task> */
    public function lockColumns(Expedient $case, int ...$statusIds): Collection
    {
        return $case->tasks()->whereIn('status_id', $statusIds)->orderBy('id')->lockForUpdate()->get();
    }

    /** @param Collection<int, Task> $column */
    private function appendPosition(Collection $column): int
    {
        return $this->increment($column->max('position') ?? 0);
    }

    public function increment(int|string $value): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($number === false || $number === PHP_INT_MAX) {
            throw new IdentityError(409, 'task_order_exhausted');
        }

        return $number + 1;
    }

    /**
     * Insert independently owned rows after locking the active case and selected status.
     * The caller authorizes its operation and supplies validated task fields.
     *
     * @param  array<string, mixed>  $fields
     */
    public function append(Expedient $case, int $statusId, array $fields): Task
    {
        $position = $this->appendPosition($this->lockColumns($case, $statusId));

        $task = $case->tasks()->forceCreate(['status_id' => $statusId, 'position' => $position]
            + Arr::only($fields, ['name', 'description', 'scheduled_date', 'scheduled_time']));
        if (! $task->exists) {
            throw new RuntimeException('Task creation did not complete.');
        }

        return $task;
    }

    /**
     * Move within the locked columns. An explicit changed order compacts the destination.
     *
     * @param  Collection<int, Task>  $columns
     */
    public function move(Task $task, Collection $columns, int $statusId, ?int $index): void
    {
        $destination = $columns->where('status_id', $statusId)
            ->sortBy([['position', 'asc'], ['id', 'asc']])->values();
        $ordered = $destination->reject(fn (Task $item): bool => $item->is($task))->values();
        if ($index === null) {
            if ($destination->last()?->is($task)) {
                return;
            }
            $this->savePosition($task, $statusId, $this->appendPosition($ordered));

            return;
        }

        if ($index > $ordered->count() + 1) {
            throw ValidationException::withMessages(['position' => __('tasks.task_position_invalid')]);
        }
        $ordered->splice($index - 1, 0, [$task]);
        if ($destination->modelKeys() === $ordered->modelKeys()) {
            return;
        }

        // Allocate above both occupied and final positions before issuing any UPDATE.
        $highest = $destination->max('position') ?? 0;
        $temporary = max(count($ordered), $this->increment($highest) - 1);
        if (count($ordered) > PHP_INT_MAX - $temporary) {
            throw new IdentityError(409, 'task_order_exhausted');
        }
        foreach ($ordered as $offset => $item) {
            $this->savePosition($item, $statusId, $temporary + $offset + 1);
        }
        foreach ($ordered as $offset => $item) {
            $this->savePosition($item, $statusId, $offset + 1);
        }
    }

    private function savePosition(Task $task, int $statusId, int $position): void
    {
        if (! $task->forceFill(['status_id' => $statusId, 'position' => $position])->save()) {
            throw new RuntimeException('Task position update did not complete.');
        }
    }
}
