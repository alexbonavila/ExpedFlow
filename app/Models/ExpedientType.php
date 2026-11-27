<?php

namespace App\Models;

use Database\Factories\ExpedientTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code'])]
class ExpedientType extends Model
{
    /** @use HasFactory<ExpedientTypeFactory> */
    use HasFactory;

    /** @return HasMany<Expedient, $this> */
    public function expedients(): HasMany
    {
        return $this->hasMany(Expedient::class);
    }

    /** @return HasMany<TaskTemplate, $this> */
    public function templates(): HasMany
    {
        return $this->hasMany(TaskTemplate::class);
    }
}
