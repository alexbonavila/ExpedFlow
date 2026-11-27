<?php

namespace App\Http\Requests;

use App\Support\AuthorizationInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class ClientRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $input = AuthorizationInput::fields($this);
        $name = $input['name'] ?? null;

        return ['name' => is_string($name) ? Str::trim($name) : $name];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['name' => ['bail', 'required', 'string', 'max:255']];
    }
}
