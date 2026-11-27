<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

final class CatalogOrder
{
    /**
     * Supply the complete scope loaded by ascending ID under write locks.
     *
     * @param  iterable<Model>  $records
     * @param  array<array-key, mixed>  $ids
     */
    public static function replace(iterable $records, array $ids): void
    {
        $byId = (new Collection($records))->keyBy('id')->all();
        if (count($ids) !== count($byId) || array_diff($ids, array_keys($byId)) !== []) {
            throw new IdentityError(409, 'order_membership_conflict', 'catalogs');
        }

        foreach (array_values($ids) as $index => $id) {
            if (! $byId[$id]->forceFill(['position' => $index + 1])->save()) {
                throw new RuntimeException('Catalog order persistence did not complete.');
            }
        }
    }
}
