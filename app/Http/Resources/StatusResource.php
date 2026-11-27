<?php

namespace App\Http\Resources;

use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** @mixin Status */
final class StatusResource extends JsonResource
{
    /** @return array{id: int, name: string, position: int} */
    public function toArray(Request $request): array
    {
        $translations = $this->name_translations;
        $name = is_array($translations)
            ? (Arr::get($translations, app()->getLocale()) ?? Arr::get($translations, config()->string('app.fallback_locale')))
            : null;

        return ['id' => $this->id, 'name' => is_string($name) ? $name : $this->name, 'position' => $this->position];
    }
}
