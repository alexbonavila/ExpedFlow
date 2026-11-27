<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /** @return Attribute<never, string> */
    protected function name(): Attribute
    {
        return Attribute::set(fn (string $value): string => Str::trim($value));
    }

    /** @return HasMany<Expedient, $this> */
    public function requestedExpedients(): HasMany
    {
        return $this->hasMany(Expedient::class, 'requester_client_id');
    }

    /** @return HasMany<Expedient, $this> */
    public function representedExpedients(): HasMany
    {
        return $this->hasMany(Expedient::class, 'represented_client_id');
    }
}
