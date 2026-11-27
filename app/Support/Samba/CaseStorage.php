<?php

namespace App\Support\Samba;

use App\Models\Expedient;
use App\Models\User;
use App\Support\BusinessWrite;
use App\Support\ExpedientLifecycle;
use App\Support\IdentityError;
use App\Support\PermissionKey;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
        return $this->perform($request, $case, Operation::Provision,
            fn (Expedient $active) => $this->provision($request, $active, Operation::Provision));
    }

    public function retry(Request $request, int $id): StorageOutcome
    {
        $case = $this->admit($request, $id, Operation::Retry);

        return $this->perform($request, $case, Operation::Retry,
            fn (Expedient $active) => $this->provision($request, $this->reserveLegacy($request, $active), Operation::Retry));
    }

    public function upload(Request $request, int $id, UploadedFile $file): StorageOutcome
    {
        $case = $this->admit($request, $id, Operation::Upload);

        return $this->perform($request, $case, Operation::Upload, function (Expedient $active) use ($file): void {
            $size = UploadInput::validate($file, StorageConfiguration::uploadLimit(config()->array('samba')));
            $this->adapter->upload($this->intent($active), $active->samba_provisioning, $file, $size);
        });
    }

    /** @param Closure(Expedient): void $work */
    private function perform(Request $request, Expedient $case, Operation $operation, Closure $work): StorageOutcome
    {
        if (DB::transactionLevel() !== 0) {
            return new StorageOutcome(Failure::AdmissionChanged);
        }

        $admitted = false;
        try {
            $this->lock->run($case->id, function () use ($request, $case, $operation, $work, &$admitted): void {
                $active = $this->admit($request, $case->id, $operation);
                $admitted = true;
                $work($active);
                $this->admit($request, $case->id, $operation);
            });

            return new StorageOutcome;
        } catch (AuthorizationException|IdentityError|ModelNotFoundException) {
            return $admitted
                ? $this->failed($request, $case, $operation, new StorageFailure(Failure::AdmissionChanged))
                : new StorageOutcome(Failure::AdmissionChanged);
        } catch (StorageFailure|QueryException $failure) {
            return $this->failed($request, $case, $operation, $failure);
        }
    }

    private function reserveLegacy(Request $request, Expedient $case): Expedient
    {
        return $case->samba_relative_path === null
            ? $this->admit($request, $case->id, Operation::Retry, reserve: true)
            : $case;
    }

    private function admit(Request $request, int $id, Operation $operation, ?string $state = null, bool $reserve = false): Expedient
    {
        $permission = $operation === Operation::Provision ? PermissionKey::CreateExpedients : PermissionKey::UploadCaseFiles;

        return $this->write->run($request, $permission, function (User $actor) use ($id, $operation, $state, $reserve): Expedient {
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

            return $reserve ? $this->reserve($case) : $case;
        });
    }

    private function provision(Request $request, Expedient $case, Operation $operation): void
    {
        $this->adapter->provision($this->intent($case), $case->samba_provisioning, function (string $state) use ($request, $case, $operation): void {
            $this->admit($request, $case->id, $operation, $state);
        });
    }

    private function intent(Expedient $case): StorageIntent
    {
        return new StorageIntent($case->id, $case->code, $case->samba_relative_path ?? throw new StorageFailure(Failure::NotReady));
    }

    private function failed(Request $request, Expedient $case, Operation $operation, StorageFailure|QueryException $failure): StorageOutcome
    {
        $reason = $failure instanceof StorageFailure ? $failure->reason : Failure::Transfer;

        return new StorageOutcome($reason, $reason === Failure::Busy
            ? null
            : $this->incidents->record($operation, $reason, $case, $request->user()?->id));
    }
}
