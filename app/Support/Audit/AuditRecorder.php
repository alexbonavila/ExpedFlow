<?php

namespace App\Support\Audit;

use App\Models\Expedient;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

final class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        AuditContext $context,
        AuditEvent $event,
        Model $subject,
        array $before = [],
        array $after = [],
        ?Expedient $case = null,
        array $metadata = [],
    ): void {
        $kind = $event->subject();
        $expectedClass = $kind->modelClass();
        $connection = $subject->getConnection();

        if (! $subject instanceof $expectedClass || $connection->transactionLevel() < 1) {
            throw new LogicException('Audits require the expected subject and an active transaction.');
        }

        $changes = AuditChanges::between($kind, $before, $after);
        if ($changes['attributes'] === [] && ($before !== [] || $after !== [])) {
            return;
        }

        $case ??= $subject instanceof Expedient ? $subject : null;
        $activity = new Activity;
        $activity->setConnection($connection->getName());
        $activity->timestamps = false;
        $activity->forceFill([
            'log_name' => $event->category(),
            'description' => $event->value,
            'event' => $event->value,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'causer_type' => $context->actorId === null ? null : (new User)->getMorphClass(),
            'causer_id' => $context->actorId,
            'created_at' => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            'attribute_changes' => $changes,
            'properties' => [
                'version' => 1,
                'origin' => $context->origin,
                'operation_id' => $context->operationId,
                'case_id' => $case?->id,
                'case_code' => AuditChanges::value('case_code', $case?->code),
                'metadata' => $this->metadata($metadata),
            ],
        ]);

        if (! $activity->save()) {
            throw new RuntimeException('The audit entry could not be saved.');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function metadata(array $metadata): array
    {
        $fields = [
            'author_id' => 'id', 'task_id' => 'id', 'template_id' => 'id', 'status_id' => 'id',
            'case_type_id' => 'id', 'task_count' => 'id', 'checklist_count' => 'id',
            'credentials_revoked' => 'flag', 'storage_reserved' => 'flag',
            'confirmed' => 'flag', 'added_ids' => 'ids', 'removed_ids' => 'ids',
            'added_permissions' => 'permissions', 'removed_permissions' => 'permissions',
        ];

        foreach ($metadata as $field => $value) {
            $metadata[$field] = AuditChanges::value($fields[$field] ?? throw new LogicException('Unknown audit metadata.'), $value);
        }

        return $metadata;
    }
}
