<?php

namespace App\Http\Resources;

use App\Models\Incident;
use App\Support\Samba\Failure;
use App\Support\Samba\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/** @mixin Incident */
final class SambaIncidentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $operation = Operation::tryFrom($this->operation);
        $reason = $operation === null ? null : Failure::tryFrom($this->type);

        $epoch = $this->getAttribute('occurred_epoch');
        if (! is_int($epoch)) {
            throw new RuntimeException('Incident timestamp was unavailable.');
        }

        return [
            'id' => $this->id,
            'case_id' => $this->expedient_id,
            'case_code' => $this->expedient_code,
            'actor_id' => $this->user_id,
            'operation' => $operation?->value ?? 'unknown',
            'type' => $reason?->value ?? 'unknown',
            'message' => __('samba.'.($reason?->value ?? 'incident_unknown')),
            'created_at' => CarbonImmutable::createFromTimestampUTC($epoch)->toISOString(),
        ];
    }
}
