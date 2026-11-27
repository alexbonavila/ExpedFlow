<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const string ADMIN_ROLE = 'admin';

    public const string SESSION_VERSION = 'identity.auth_version';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'immutable_datetime',
            'auth_version' => 'integer',
            'password' => 'hashed',
        ];
    }

    /** Sanctum uses both a transient browser credential and a persisted bearer credential. */
    public function currentAccessToken(): ?HasAbilities
    {
        return $this->accessToken;
    }

    /** @return BelongsToMany<Task, $this> */
    public function assignedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class);
    }

    /** @return HasMany<TaskComment, $this> */
    public function taskComments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'author_user_id');
    }

    /** @return HasMany<ExpedientUserPreference, $this> */
    public function expedientPreferences(): HasMany
    {
        return $this->hasMany(ExpedientUserPreference::class);
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}
