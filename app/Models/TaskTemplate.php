<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['expedient_type_id', 'name', 'description', 'position'])]
class TaskTemplate extends Model
{
    /** @return BelongsTo<ExpedientType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ExpedientType::class, 'expedient_type_id');
    }

    /** @return HasMany<TaskTemplateChecklistItem, $this> */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskTemplateChecklistItem::class);
    }
}
