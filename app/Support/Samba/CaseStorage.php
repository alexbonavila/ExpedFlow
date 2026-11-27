<?php

namespace App\Support\Samba;

use App\Models\Expedient;
use App\Models\User;
use App\Support\BusinessWrite;
use App\Support\ExpedientLifecycle;
use App\Support\IdentityError;
use App\Support\PermissionKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CaseStorage
{
    public function __construct(
        private readonly BusinessWrite $write,
        private readonly ExpedientLifecycle $cases,
        private readonly CaseStorageLock $lock,
        private readonly SambaAdapter $adapter,
        private readonly IncidentRecorder $incidents,
    ) {}

    /** Call while the new case and its annual number are still in the same transaction. */
    public function reserve(Expedient $case): Expedient
    {
        if ($case->samba_relative_path === null) {
            $case->forceFill([
                'samba_relative_path' => StorageIntent::target($case->code, $case->name, config('samba.cases')),
            ]);
            if (! $case->save()) {
                throw new StorageFailure(Failure::Transfer);
            }
        }

        return $case;
    }

    public function created(Request $request, Expedient $case): StorageOutcome
    {
        if (DB::transactionLevel() !== 0) {
            return new StorageOutcome(Failure::AdmissionChanged);
        }

        try {
            $this->lock->run($case->id, function () use ($request, $case): void {
                $active = $this->admit($request, $case->id, Operation::Provision);
                $this->provision($request, $active, Operation::Provision);
            });

            return new StorageOutcome;
        } catch (AuthorizationException|IdentityError|ModelNotFoundException) {
            return new StorageOutcome(Failure::AdmissionChanged);
        } catch (StorageFailure|QueryException $failure) {
            return $this->failed($request, $case, Operation::Provision, $failure);
        }
    }

    private function admit(Request $request, int $id, Operation $operation, ?string $state = null): Expedient
    {
        $permission = $operation === Operation::Provision ? PermissionKey::CreateExpedients : PermissionKey::UploadCaseFiles;

        return $this->write->run($request, $permission, function (User $actor) use ($id, $operation, $state): Expedient {
            $gate = Gate::forUser($actor);
            $initial = $operation === Operation::Provision;
            $gate->authorize($initial ? 'create' : 'viewAny', Expedient::class);
            $case = $this->cases->lockParent($id);
            if (! $initial) {
                $gate->authorize('upload', $case);
            }
            if ($state !== null) {
                if (! $case->forceFill(['samba_provisioning' => $state])->save()) {
                    throw new StorageFailure(Failure::Transfer);
                }
            }

            return $case;
        });
    }

    private function provision(Request $request, Expedient $case, Operation $operation): void
    {
        $intent = new StorageIntent($case->id, $case->code, $case->samba_relative_path ?? throw new StorageFailure(Failure::NotReady));
        $this->adapter->provision($intent, $case->samba_provisioning, function (string $state) use ($request, $case, $operation): void {
            $this->admit($request, $case->id, $operation, $state);
        });
        $this->admit($request, $case->id, $operation);
    }

    private function failed(Request $request, Expedient $case, Operation $operation, StorageFailure|QueryException $failure): StorageOutcome
    {
        $reason = $failure instanceof StorageFailure ? $failure->reason : Failure::Transfer;

        return new StorageOutcome($reason, $this->incidents->record($operation, $reason, $case, $request->user()?->id));
    }
}
