<?php

namespace App\Support;

use App\Models\ExpedientType;
use App\Models\Status;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CatalogWrite
{
    /** Call after BusinessWrite has checked the catalog management permission. */
    public static function delete(User $actor, ExpedientType|Status $record, string $conflict): bool
    {
        if (Gate::forUser($actor)->denies('delete', $record)) {
            throw new IdentityError(409, $conflict, 'catalogs');
        }

        try {
            return $record->newQuery()->whereKey($record->id)->delete() === 1;
        } catch (QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1451) {
                throw new IdentityError(409, $conflict, 'catalogs');
            }

            throw $error;
        }
    }

    public static function deleted(string $code): JsonResponse
    {
        return response()->json(['message' => __('catalogs.'.$code), 'code' => $code]);
    }
}
