<?php

namespace App\Http\Controllers;

use App\Http\Resources\SambaIncidentResource;
use App\Models\Incident;
use App\Support\AuthorizationInput;
use App\Support\Samba\Failure;
use App\Support\Samba\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SambaIncidentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = Validator::make($request->query(), [
            'case_code' => ['sometimes', 'string', 'regex:/^[0-9]{9}$/D'],
            'operation' => ['sometimes', Rule::enum(Operation::class)],
            'type' => ['sometimes', Rule::enum(Failure::class)],
        ])->validate();
        $incidents = $this->incidents();
        foreach (['case_code' => 'expedient_code', 'operation' => 'operation', 'type' => 'type'] as $key => $column) {
            if (isset($filters[$key])) {
                $incidents->where($column, $filters[$key]);
            }
        }

        return SambaIncidentResource::collection($incidents->orderByDesc('created_at')->orderByDesc('id')->get());
    }

    public function show(string $incident): SambaIncidentResource
    {
        return new SambaIncidentResource($this->incidents()->findOrFail(AuthorizationInput::identifier($incident)));
    }

    /** @return Builder<Incident> */
    private function incidents(): Builder
    {
        return Incident::query()->select(['id', 'expedient_id', 'expedient_code', 'user_id', 'operation', 'type'])
            ->selectRaw('UNIX_TIMESTAMP(created_at) AS occurred_epoch')->withCasts(['occurred_epoch' => 'integer']);
    }
}
