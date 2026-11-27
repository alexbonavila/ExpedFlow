<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'name_translations', 'position'])]
class Status extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['name_translations' => 'array'];
    }

    /** @return HasMany<Expedient, $this> */
    public function expedients(): HasMany
    {
        return $this->hasMany(Expedient::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
