<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'expedient_id', 'position', 'column_version', 'hidden'])]
class ExpedientUserPreference extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['hidden' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Expedient, $this> */
    public function expedient(): BelongsTo
    {
        return $this->belongsTo(Expedient::class);
    }
}
