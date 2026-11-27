<?php

namespace App\Http\Resources;

use App\Models\ExpedientType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExpedientType */
final class ExpedientTypeResource extends JsonResource
{
    /** @return array{id: int, name: string, code: string} */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'code' => $this->code];
    }
}
