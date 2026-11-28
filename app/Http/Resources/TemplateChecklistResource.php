<?php

namespace App\Http\Resources;

use App\Models\TaskTemplateChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaskTemplateChecklistItem */
final class TemplateChecklistResource extends JsonResource
{
    /** @return array{id: int, task_template_id: int, name: string, position: int, created_at: ?string, updated_at: ?string} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_template_id' => $this->task_template_id,
            'name' => $this->name,
            'position' => $this->position,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
