<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'year', 'sequence_number', 'expedient_type_id', 'requester_client_id',
    'represented_client_id', 'status_id', 'name', 'description', 'column_position', 'column_version',
])]
class Expedient extends Model
{
    use SoftDeletes;

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
}
