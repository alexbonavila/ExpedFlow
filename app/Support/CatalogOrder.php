<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
        $byId = (new Collection($records))->keyBy('id');
        if (count($ids) !== $byId->count() || array_diff($ids, $byId->keys()->all()) !== []) {
            throw new IdentityError(409, 'order_membership_conflict', 'catalogs');
        }

        foreach (array_values($ids) as $index => $id) {
            $byId->get($id)?->forceFill(['position' => $index + 1])->save();
        }
    }
}
