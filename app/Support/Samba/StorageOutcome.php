<?php

namespace App\Support\Samba;

final readonly class StorageOutcome
{
    public function __construct(public ?Failure $failure = null, public ?int $incident = null) {}

    /** @return array{status: string, code: string, message: string, incident_id: int|null} */
    public function payload(): array
    {
        $code = $this->failure?->value ?? 'storage_ready';

        return [
            'status' => $this->failure === null ? 'ready' : 'failed',
            'code' => $code,
            'message' => __('samba.'.$code),
            'incident_id' => $this->incident,
        ];
    }
}
