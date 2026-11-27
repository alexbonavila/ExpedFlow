<?php

namespace App\Http\Requests;

use App\Support\AuthorizationInput;
use Illuminate\Foundation\Http\FormRequest;

final class TaskMoveRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return AuthorizationInput::fields($this);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'status_id' => ['required', 'integer:strict', 'min:1'],
            'position' => ['sometimes', 'required', 'integer:strict', 'min:1'],
        ];
    }
}
