<?php

namespace App\Http\Resources;

use App\Models\TaskComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaskComment */
final class TaskCommentResource extends JsonResource
{
    public const string AUTHOR = 'author:id,name';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'author_user_id' => $this->author_user_id,
            'author' => ['id' => $this->author_user_id, 'name' => $this->author?->name],
            'body' => $this->body,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->whenNotNull($this->deleted_at?->toISOString()),
        ];
    }
}
