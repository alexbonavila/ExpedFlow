<?php

namespace App\Http\Resources;

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Task */
final class TaskResource extends JsonResource
{
    public const array RELATIONS = ['status:id,name,name_translations,position', 'assignees:id,name,disabled_at'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expedient_id' => $this->expedient_id,
            'status_id' => $this->status_id,
            'status' => new StatusResource($this->status),
            'name' => $this->name,
            'description' => $this->description,
            'scheduled_date' => $this->scheduled_date?->format('Y-m-d'),
            'scheduled_time' => $this->scheduled_time,
            'position' => $this->position,
            'assignees' => $this->assignees->sortBy('id')->values()->map(self::assignee(...)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->whenNotNull($this->deleted_at?->toISOString()),
        ];
    }

    /** @return array{id: int, name: string, active: bool} */
    public static function assignee(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'active' => $user->disabled_at === null];
    }
}
