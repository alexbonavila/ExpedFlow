<?php

namespace App\Support\Samba;

use App\Models\Expedient;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class IncidentRecorder
{
    public function record(Operation $operation, Failure $reason, Expedient $case, ?int $actor): ?int
    {
        try {
            return DB::transaction(function () use ($operation, $reason, $case, $actor): int {
                $user = $actor === null ? null : User::query()->sharedLock()->find($actor);
                $parent = Expedient::withTrashed()->sharedLock()->find($case->id);
                $incident = Incident::query()->forceCreate([
                    'type' => $reason->value,
                    'operation' => $operation->value,
                    'message' => 'A case storage operation did not complete.',
                    'user_id' => $user?->id,
                    'expedient_id' => $parent?->id,
                    'expedient_code' => $case->code,
                    'created_at' => now('UTC'),
                ]);

                if (! $incident->exists) {
                    throw new StorageFailure(Failure::Transfer);
                }

                return $incident->id;
            }, 3);
        } catch (Throwable) {
            Log::error('Case storage incident could not be persisted.', [
                'operation' => $operation->value, 'reason' => $reason->value,
            ]);

            return null;
        }
    }
}
