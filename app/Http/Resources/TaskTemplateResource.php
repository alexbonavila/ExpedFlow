<?php

namespace App\Http\Resources;

use App\Models\TaskTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaskTemplate */
final class TaskTemplateResource extends JsonResource
{
    /** @return array{id: int, expedient_type_id: int, name: string, description: ?string, position: int, created_at: ?string, updated_at: ?string} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expedient_type_id' => $this->expedient_type_id,
            'name' => $this->name,
            'description' => $this->description,
            'position' => $this->position,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
