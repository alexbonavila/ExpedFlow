<?php

namespace App\Support;

use App\Models\Expedient;
use App\Models\ExpedientSequence;

final class AnnualNumbering
{
    /**
     * Reserve within the same transaction that inserts the case.
     *
     * @return array{year: int, sequence_number: int}
     */
    public function allocate(): array
    {
        $year = now(config()->string('app.timezone'))->year;
        ExpedientSequence::query()->upsert(['year' => $year, 'last_number' => 0], ['year'], ['year']);
        $sequence = ExpedientSequence::query()->lockForUpdate()->findOrFail($year);

        if (Expedient::withTrashed()->where('year', $year)->where('sequence_number', '>', $sequence->last_number)->exists()) {
            throw new IdentityError(409, 'annual_sequence_inconsistent');
        }
        if ($sequence->last_number >= 999) {
            throw new IdentityError(409, 'annual_sequence_exhausted');
        }

        $number = $sequence->last_number + 1;
        $sequence->forceFill(['last_number' => $number])->save();

        return ['year' => $year, 'sequence_number' => $number];
    }
}
