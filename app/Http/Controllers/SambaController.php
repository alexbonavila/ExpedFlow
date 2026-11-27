<?php

namespace App\Http\Controllers;

use App\Support\AuthorizationInput;
use App\Support\Samba\CaseStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SambaController extends Controller
{
    public function retry(Request $request, string $expedient, CaseStorage $storage): JsonResponse
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['request' => __('samba.unexpected_fields')]);
        }
        $result = $storage->retry($request, AuthorizationInput::identifier($expedient));

        return response()->json($result->payload(), $result->failure?->status() ?? 200);
    }
}
