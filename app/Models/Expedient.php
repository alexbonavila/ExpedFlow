<?php

namespace App\Models;

use Database\Factories\ExpedientFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Hidden(['samba_relative_path'])]
class Expedient extends Model
{
    /** @use HasFactory<ExpedientFactory> */
    use HasFactory, SoftDeletes;

    /** @return BelongsTo<ExpedientType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ExpedientType::class, 'expedient_type_id');
    }

    /** @return BelongsTo<ExpedientSequence, $this> */
    public function annualSequence(): BelongsTo
    {
        return $this->belongsTo(ExpedientSequence::class, 'year');
    }

    /** @return BelongsTo<Client, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'requester_client_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function representedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'represented_client_id');
    }

    /** @return BelongsTo<Status, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** @return HasMany<ExpedientUserPreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(ExpedientUserPreference::class);
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}
