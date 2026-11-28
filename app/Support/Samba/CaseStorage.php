<?php

namespace App\Support\Samba;

use App\Models\Expedient;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
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
use Illuminate\Support\Facades\Log;
use Throwable;

final class CaseStorage
{
    public function __construct(
        private readonly BusinessWrite $write,
        private readonly ExpedientLifecycle $cases,
        private readonly CaseStorageLock $lock,
        private readonly SambaAdapter $adapter,
        private readonly IncidentRecorder $incidents,
        private readonly AuditRecorder $audit,
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

    public function created(Request $request, Expedient $case, AuditContext $context): StorageOutcome
    {
        return $this->perform($request, $case, Operation::Provision, $context,
            fn (Expedient $active) => $this->provision($request, $active, Operation::Provision));
    }

    public function retry(Request $request, int $id): StorageOutcome
    {
        [$case, $context] = $this->admit($request, $id, Operation::Retry);

        return $this->perform($request, $case, Operation::Retry, $context,
            fn (Expedient $active) => $this->provision($request, $this->reserveLegacy($request, $active), Operation::Retry));
    }

    public function upload(Request $request, int $id, UploadedFile $file): StorageOutcome
    {
        [$case, $context] = $this->admit($request, $id, Operation::Upload);

        return $this->perform($request, $case, Operation::Upload, $context, function (Expedient $active) use ($file): bool {
            $size = UploadInput::validate($file, StorageConfiguration::uploadLimit(config()->array('samba')));
            $this->adapter->upload($this->intent($active), $active->samba_provisioning, $file, $size);

            return true;
        });
    }

    /** @param Closure(Expedient): bool $work Returns whether storage confirmed a new change. */
    private function perform(Request $request, Expedient $case, Operation $operation, AuditContext $context, Closure $work): StorageOutcome
    {
        if (DB::transactionLevel() !== 0) {
            return new StorageOutcome(Failure::AdmissionChanged);
        }

        $admitted = $confirmed = false;
        try {
            $this->lock->run($case->id, function () use ($request, $case, $operation, $work, &$admitted, &$confirmed): void {
                $active = $this->admit($request, $case->id, $operation)[0];
                $admitted = true;
                $confirmed = $work($active);
                $this->admit($request, $case->id, $operation);
            });

            return new StorageOutcome;
        } catch (AuthorizationException|IdentityError|ModelNotFoundException) {
            return $admitted
                ? $this->failed($request, $case, $operation, new StorageFailure(Failure::AdmissionChanged))
                : new StorageOutcome(Failure::AdmissionChanged);
        } catch (StorageFailure|QueryException $failure) {
            return $this->failed($request, $case, $operation, $failure);
        } finally {
            if ($confirmed) {
                $this->recordConfirmed($context, $case, $operation);
            }
        }
    }

    private function recordConfirmed(AuditContext $context, Expedient $case, Operation $operation): void
    {
        $event = match ($operation) {
            Operation::Provision => AuditEvent::StorageProvisioned,
            Operation::Retry => AuditEvent::StorageRetried,
            Operation::Upload => AuditEvent::StorageUploaded,
        };

        try {
            DB::transaction(fn () => $this->audit->record($context, $event, $case, metadata: ['confirmed' => true]), 3);
        } catch (Throwable) {
            rescue(fn () => Log::error('Confirmed case storage operation could not be audited.', [
                'operation' => $operation->value, 'operation_id' => $context->operationId,
            ]), report: false);
            rescue(fn () => $this->incidents->record($operation, Failure::AuditPersistence, $case, $context->actorId), report: false);
        }
    }

    private function reserveLegacy(Request $request, Expedient $case): Expedient
    {
        return $case->samba_relative_path === null
            ? $this->admit($request, $case->id, Operation::Retry, reserve: true)[0]
            : $case;
    }

    /** @return array{Expedient, AuditContext} */
    private function admit(Request $request, int $id, Operation $operation, ?string $state = null, bool $reserve = false): array
    {
        $permission = $operation === Operation::Provision ? PermissionKey::CreateExpedients : PermissionKey::UploadCaseFiles;

        return $this->write->run($request, $permission, function (User $actor, AuditContext $context) use ($id, $operation, $state, $reserve): array {
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

            return [$reserve ? $this->reserve($case) : $case, $context];
        });
    }

    private function provision(Request $request, Expedient $case, Operation $operation): bool
    {
        return $this->adapter->provision($this->intent($case), $case->samba_provisioning, function (string $state) use ($request, $case, $operation): void {
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
