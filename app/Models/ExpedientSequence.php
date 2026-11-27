<?php

namespace App\Models;

use Database\Factories\ExpedientSequenceFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table(key: 'year')]
#[WithoutIncrementing]
#[WithoutTimestamps]
class ExpedientSequence extends Model
{
    /** @use HasFactory<ExpedientSequenceFactory> */
    use HasFactory;

    /** @return HasMany<Expedient, $this> */
    public function expedients(): HasMany
    {
        return $this->hasMany(Expedient::class, 'year');
    }
}
