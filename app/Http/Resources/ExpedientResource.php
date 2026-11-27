<?php

namespace App\Http\Resources;

use App\Models\Expedient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/** @mixin Expedient */
final class ExpedientResource extends JsonResource
{
    public const array RELATIONS = ['type:id,name,code', 'requester:id,name', 'representedClient:id,name', 'status:id,name,name_translations,position'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $requester = $this->requester ?? throw new RuntimeException('The case requester is missing.');
        $represented = $this->representedClient;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'expedient_type_id' => $this->expedient_type_id,
            'requester_client_id' => $this->requester_client_id,
            'represented_client_id' => $this->represented_client_id,
            'status_id' => $this->status_id,
            'type' => new ExpedientTypeResource($this->type),
            'requester' => ['id' => $requester->id, 'name' => $requester->name],
            'represented_client' => $represented === null ? null : [
                'id' => $represented->id, 'name' => $represented->name,
            ],
            'status' => new StatusResource($this->status),
            'column_position' => $this->column_position,
            'column_version' => $this->column_version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->whenNotNull($this->deleted_at?->toISOString()),
        ];
    }
}
