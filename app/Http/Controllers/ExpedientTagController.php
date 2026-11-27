<?php

namespace App\Http\Controllers;

use App\Http\Resources\TagResource;
use App\Models\Expedient;
use App\Models\Tag;
use App\Models\User;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\CatalogInput;
use App\Support\ExpedientLifecycle;
use App\Support\PermissionKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ExpedientTagController extends Controller
{
    public function __construct(private readonly BusinessWrite $write, private readonly ExpedientLifecycle $lifecycle) {}

    public function index(string $expedient): AnonymousResourceCollection
    {
        $case = Expedient::query()->findOrFail(AuthorizationInput::identifier($expedient));
        Gate::authorize('view', $case);

        return TagResource::collection($case->tags()->orderBy('tags.name')->orderBy('tags.id')->get());
    }

    public function replace(Request $request, string $expedient): JsonResponse
    {
        $id = AuthorizationInput::identifier($expedient);
        $ids = Arr::array(CatalogInput::validate($request, CatalogInput::identifiers('tag_ids')), 'tag_ids');
        $membership = $this->write->run($request, PermissionKey::AssociateTags, function (User $actor) use ($id, $ids): array {
            Gate::forUser($actor)->authorize('viewAny', Expedient::class);
            $case = $this->lifecycle->lockParent($id);
            Gate::forUser($actor)->authorize('associateTags', $case);

            $tags = Tag::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get(['id'])->modelKeys();
            if (count($tags) !== count($ids)) {
                throw ValidationException::withMessages(['tag_ids' => __('catalogs.tag_reference_invalid')]);
            }

            $relation = $case->tags();
            $current = $relation->newPivotQuery()->orderBy('tag_id')->lockForUpdate()->pluck('tag_id')->all();
            $relation->detach(array_diff($current, $tags));
            $relation->attach(array_diff($tags, $current));

            return ['tag_ids' => $tags];
        });

        return response()->json(['data' => $membership]);
    }
}
