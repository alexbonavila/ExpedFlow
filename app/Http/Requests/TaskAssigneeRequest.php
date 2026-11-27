<?php

namespace App\Http\Requests;

use App\Support\AuthorizationInput;
use Illuminate\Foundation\Http\FormRequest;

final class TaskAssigneeRequest extends FormRequest
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
            'user_ids' => ['present', 'array', 'list'],
            'user_ids.*' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
        ];
    }
}
