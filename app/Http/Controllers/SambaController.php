<?php

namespace App\Http\Controllers;

use App\Support\AuthorizationInput;
use App\Support\Samba\CaseStorage;
use App\Support\Samba\UploadInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

    public function upload(Request $request, string $expedient, CaseStorage $storage): JsonResponse
    {
        $type = strtolower(trim(explode(';', $request->headers->get('Content-Type', ''), 2)[0]));
        $file = $request->file('file');
        if ($type !== 'multipart/form-data' || ! $file instanceof UploadedFile
            || array_keys($request->all()) !== ['file'] || $request->input() !== []) {
            throw UploadInput::invalid('upload_invalid');
        }

        $id = AuthorizationInput::identifier($expedient);
        $result = $storage->upload($request, $id, $file);
        if ($result->failure !== null) {
            return response()->json($result->payload(), $result->failure->status());
        }

        return response()->json(['message' => __('samba.upload_saved'), 'code' => 'upload_saved', 'case_id' => $id], 201);
    }
}
