<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExpedientTypeResource;
use App\Http\Resources\StatusResource;
use App\Models\ExpedientType;
use App\Models\Status;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CaseLookupController extends Controller
{
    public function types(): AnonymousResourceCollection
    {
        return ExpedientTypeResource::collection(ExpedientType::query()->orderBy('code')->orderBy('id')->get());
    }

    public function statuses(): AnonymousResourceCollection
    {
        return StatusResource::collection(Status::query()->orderBy('position')->orderBy('id')->get());
    }
}
