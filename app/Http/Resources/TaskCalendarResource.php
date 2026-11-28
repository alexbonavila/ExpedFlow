<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Task */
final class TaskCalendarResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expedient_id' => $this->expedient_id,
            'expedient_code' => $this->getAttributeValue('calendar_case_code'),
            'expedient_name' => $this->getAttributeValue('calendar_case_name'),
            'name' => $this->name,
            'scheduled_date' => $this->scheduled_date?->format('Y-m-d'),
            'scheduled_time' => $this->scheduled_time,
            'all_day' => $this->scheduled_time === null,
        ];
    }
}
