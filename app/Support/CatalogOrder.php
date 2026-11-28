<?php

namespace App\Support;

use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

final class CatalogOrder
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Supply the complete scope loaded by ascending ID under write locks.
     *
     * @param  iterable<Model>  $records
     * @param  array<array-key, mixed>  $ids
     */
    public function replace(iterable $records, array $ids, AuditContext $context, AuditEvent $event): void
    {
        $byId = (new Collection($records))->keyBy('id')->all();
        if (count($ids) !== count($byId) || array_diff($ids, array_keys($byId)) !== []) {
            throw new IdentityError(409, 'order_membership_conflict', 'catalogs');
        }

        foreach (array_values($ids) as $index => $id) {
            $record = $byId[$id];
            $previous = $record->getRawOriginal('position');
            if (! $record->forceFill(['position' => $index + 1])->save()) {
                throw new RuntimeException('Catalog order persistence did not complete.');
            }
            $this->audit->record($context, $event, $record, ['position' => $previous], ['position' => $index + 1]);
        }
    }
}
