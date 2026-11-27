<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpedientIndexRequest;
use App\Http\Requests\ExpedientRequest;
use App\Http\Resources\ExpedientResource;
use App\Models\Expedient;
use App\Models\User;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\ExpedientLifecycle;
use App\Support\ExpedientWriter;
use App\Support\PermissionKey;
use App\Support\Samba\CaseStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

final class ExpedientController extends Controller
{
    public function __construct(
        private readonly BusinessWrite $write,
        private readonly ExpedientWriter $writer,
        private readonly ExpedientLifecycle $lifecycle,
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
        $case = $this->write->run($request, PermissionKey::CreateExpedients,
            fn (User $actor): Expedient => $storage->reserve($this->writer->create($actor, $input))->load(ExpedientResource::RELATIONS));

        return (new ExpedientResource($case))->additional(['storage' => $storage->created($request, $case)->payload()])
            ->response()->setStatusCode(201);
    }

    public function update(ExpedientRequest $request, string $expedient): ExpedientResource
    {
        $id = AuthorizationInput::identifier($expedient);
        $input = $request->validated();
        $case = $this->write->run($request, PermissionKey::UpdateExpedients,
            fn (User $actor): Expedient => $this->writer->update($actor, $id, $input)->load(ExpedientResource::RELATIONS));

        return new ExpedientResource($case);
    }

    public function destroy(Request $request, string $expedient): JsonResponse
    {
        $id = AuthorizationInput::identifier($expedient);
        $this->write->run($request, PermissionKey::DeleteExpedients, fn (User $actor) => $this->lifecycle->delete($actor, $id));

        return response()->json(['message' => __('identity.case_deleted'), 'code' => 'case_deleted']);
    }

    public function restore(Request $request, string $expedient): ExpedientResource
    {
        $id = AuthorizationInput::identifier($expedient);
        $case = $this->write->run($request, PermissionKey::RestoreExpedients,
            fn (User $actor): Expedient => $this->lifecycle->restore($actor, $id)->load(ExpedientResource::RELATIONS));

        return new ExpedientResource($case);
    }

    public function forceDelete(Request $request, string $expedient): JsonResponse
    {
        $id = AuthorizationInput::identifier($expedient);
        $this->write->run($request, PermissionKey::ForceDeleteExpedients, fn (User $actor) => $this->lifecycle->forceDelete($actor, $id));

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
