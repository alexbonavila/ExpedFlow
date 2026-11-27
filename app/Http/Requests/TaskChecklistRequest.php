<?php

namespace App\Http\Requests;

use App\Support\AuthorizationInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class TaskChecklistRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $input = AuthorizationInput::fields($this);
        if (is_string($input['name'] ?? null)) {
            $input['name'] = Str::trim($input['name']);
        }

        return $input;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => [...($this->isMethod('post') ? [] : ['sometimes']), 'required', 'string', 'max:255'],
            'completed' => ['sometimes', 'required', 'boolean:strict'],
        ];
    }
}
