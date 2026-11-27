<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'color'])]
class Tag extends Model
{
    /** @return Attribute<never, string> */
    protected function name(): Attribute
    {
        return Attribute::set(fn (string $value): string => Str::trim($value));
    }

    /** @return BelongsToMany<Expedient, $this> */
    public function expedients(): BelongsToMany
    {
        return $this->belongsToMany(Expedient::class);
    }
}
