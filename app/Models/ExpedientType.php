<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code'])]
class ExpedientType extends Model
{
    /** @return HasMany<Expedient, $this> */
    public function expedients(): HasMany
    {
        return $this->hasMany(Expedient::class);
    }
}
