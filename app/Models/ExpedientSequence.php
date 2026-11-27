<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['year', 'last_number'])]
#[Table(key: 'year', incrementing: false, timestamps: false)]
class ExpedientSequence extends Model
{
    /** @return HasMany<Expedient, $this> */
    public function expedients(): HasMany
    {
        return $this->hasMany(Expedient::class, 'year');
    }
}
