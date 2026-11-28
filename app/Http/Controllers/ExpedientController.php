<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpedientIndexRequest;
use App\Http\Requests\ExpedientRequest;
use App\Http\Resources\ExpedientResource;
use App\Models\Expedient;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditSubject;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\ExpedientLifecycle;
use App\Support\ExpedientWriter;
use App\Support\IdentityError;
use App\Support\PermissionKey;
use App\Support\Samba\CaseStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

final class ExpedientController extends Controller
{
    public function __construct(
        private readonly BusinessWrite $write,
        private readonly ExpedientWriter $writer,
        private readonly ExpedientLifecycle $lifecycle,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(ExpedientIndexRequest $request): AnonymousResourceCollection
    {
        return $this->listing(Expedient::query(), $request->validated());
    }

    public function trash(ExpedientIndexRequest $request): AnonymousResourceCollection
    {
        return $this->listing(Expedient::onlyTrashed(), $request->validated());
    }

    public function show(string $expedient): ExpedientResource
    {
        $case = Expedient::query()->with(ExpedientResource::RELATIONS)->findOrFail(AuthorizationInput::identifier($expedient));
        Gate::authorize('view', $case);

        return new ExpedientResource($case);
    }

    public function showTrashed(string $expedient): ExpedientResource
    {
        $case = Expedient::onlyTrashed()->with(ExpedientResource::RELATIONS)->findOrFail(AuthorizationInput::identifier($expedient));
        Gate::authorize('viewTrash', $case);

        return new ExpedientResource($case);
    }

    public function store(ExpedientRequest $request, CaseStorage $storage): JsonResponse
    {
        $input = $request->validated();
        try {
            $case = $this->write->run($request, PermissionKey::CreateExpedients,
                function (User $actor, AuditContext $context) use ($storage, $input): Expedient {
                    $case = $storage->reserve($this->writer->create($actor, $input, $context));
                    $this->audit->record($context, AuditEvent::CaseCreated, $case,
                        after: AuditSubject::CaseRecord->snapshot($case) + ['state' => 'active'],
                        metadata: ['storage_reserved' => true]);

                    return $case->load(ExpedientResource::RELATIONS);
                });
        } catch (QueryException $exception) {
            if (! str_contains($exception->getSql(), 'samba_relative_path')) {
                throw $exception;
            }
            Log::error('Case storage intent could not be persisted.');

            throw new IdentityError(500, 'server_error');
        }

        return (new ExpedientResource($case))->additional(['storage' => $storage->created($request, $case)->payload()])
            ->response()->setStatusCode(201);
    }

    public function update(ExpedientRequest $request, string $expedient): ExpedientResource
    {
        $id = AuthorizationInput::identifier($expedient);
        $input = $request->validated();
        $case = $this->write->run($request, PermissionKey::UpdateExpedients,
            fn (User $actor, AuditContext $context): Expedient => $this->writer->update($actor, $id, $input, $context)->load(ExpedientResource::RELATIONS));

        return new ExpedientResource($case);
    }

    public function destroy(Request $request, string $expedient): JsonResponse
    {
        $id = AuthorizationInput::identifier($expedient);
        $this->write->run($request, PermissionKey::DeleteExpedients, fn (User $actor, AuditContext $context) => $this->lifecycle->delete($actor, $id, $context));

        return response()->json(['message' => __('identity.case_deleted'), 'code' => 'case_deleted']);
    }

    public function restore(Request $request, string $expedient): ExpedientResource
    {
        $id = AuthorizationInput::identifier($expedient);
        $case = $this->write->run($request, PermissionKey::RestoreExpedients,
            fn (User $actor, AuditContext $context): Expedient => $this->lifecycle->restore($actor, $id, $context)->load(ExpedientResource::RELATIONS));

        return new ExpedientResource($case);
    }

    public function forceDelete(Request $request, string $expedient): JsonResponse
    {
        $id = AuthorizationInput::identifier($expedient);
        $this->write->run($request, PermissionKey::ForceDeleteExpedients, fn (User $actor, AuditContext $context) => $this->lifecycle->forceDelete($actor, $id, $context));

        return response()->json(['message' => __('identity.case_force_deleted'), 'code' => 'case_force_deleted']);
    }

    /**
     * @param  Builder<Expedient>  $query
     * @param  array<string, mixed>  $input
     */
    private function listing(Builder $query, array $input): AnonymousResourceCollection
    {
        foreach (Arr::only($input, ['status_id', 'expedient_type_id', 'requester_client_id', 'represented_client_id']) as $field => $id) {
            $query->where($field, $id);
        }
        if (isset($input['q'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Arr::string($input, 'q')).'%';
            $query->where(fn (Builder $matches): Builder => $matches
                ->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("code LIKE ? ESCAPE '!'", [$term]));
        }

        return ExpedientResource::collection($query->with(ExpedientResource::RELATIONS)
            ->orderBy(Arr::string($input, 'sort', 'code'), ($input['direction'] ?? null) === 'desc' ? 'desc' : 'asc')->orderBy('id')->get());
    }
}
