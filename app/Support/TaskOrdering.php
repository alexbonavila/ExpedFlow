<?php

namespace App\Support;

use App\Models\Expedient;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;

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
    public function appendPosition(Collection $column): int
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

        return $case->tasks()->forceCreate(['status_id' => $statusId, 'position' => $position] + $fields);
    }
}
