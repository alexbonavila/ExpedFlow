<?php

namespace App\Support\Audit;

use App\Models\Expedient;
use App\Models\Task;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

final class AuditTaskDescendants
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** The case and, for an independent task deletion, the task are locked by the caller. */
    public function record(AuditContext $context, Expedient $case, ?Task $task = null): void
    {
        $connection = $case->getConnection();
        $subject = $task ?? $case;
        $event = $task === null ? AuditEvent::CaseDescendantsRemoved : AuditEvent::TaskDescendantsRemoved;
        $tasks = $connection->table('tasks')->where('expedient_id', $case->id);
        if ($task !== null) {
            $tasks->where('id', $task->id);
        } else {
            $this->recordIds($tasks->clone()->select('id'), 'task_ids', $context, $event, $subject, $case);
        }

        $taskIds = $tasks->select('id');
        foreach (['task_comments' => 'comment_ids', 'task_checklist_items' => 'checklist_ids'] as $table => $field) {
            $related = $connection->table($table)->select('id')->whereIn('task_id', $taskIds);
            $this->recordIds($related, $field, $context, $event, $subject, $case);
        }
        $connection->table('task_user')->whereIn('task_id', $taskIds)
            ->orderBy('task_id')->orderBy('user_id')->chunk(500, function (Collection $rows) use ($context, $event, $subject, $case): void {
                $pairs = $rows->map(fn (object $row): string => $row->task_id.':'.$row->user_id)->values()->all();
                $this->audit->record($context, $event, $subject, case: $case, metadata: ['assignment_pairs' => $pairs]);
            });
    }

    private function recordIds(
        Builder $query,
        string $field,
        AuditContext $context,
        AuditEvent $event,
        Expedient|Task $subject,
        Expedient $case,
    ): void {
        $query->chunkById(500, function (Collection $rows) use ($field, $context, $event, $subject, $case): void {
            $this->audit->record($context, $event, $subject, case: $case, metadata: [$field => $rows->pluck('id')->all()]);
        });
    }
}
