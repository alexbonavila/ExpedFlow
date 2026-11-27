<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

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
