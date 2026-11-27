<?php

namespace App\Support;

use App\Models\Expedient;
use App\Models\Status;
use Illuminate\Validation\ValidationException;

final class CaseOrdering
{
    public function lockStatuses(int ...$ids): void
    {
        $unique = array_values(array_unique($ids));
        $locked = Status::query()->whereKey($unique)->orderBy('id')->lockForUpdate()->get(['id']);
        if ($locked->count() !== count($unique)) {
            throw ValidationException::withMessages(['status_id' => __('identity.case_reference_invalid')]);
        }
    }

    /** Call after locking the destination status in the write transaction. */
    public function appendTo(int $statusId): int
    {
        return $this->increment(Expedient::withTrashed()->where('status_id', $statusId)->max('column_position') ?? 0);
    }

    public function increment(int|string $value): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($number === false || $number === PHP_INT_MAX) {
            throw new IdentityError(409, 'case_order_exhausted');
        }

        return $number + 1;
    }
}
