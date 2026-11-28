<?php

namespace App\Http\Requests;

use App\Support\TaskInput;
use Illuminate\Foundation\Http\FormRequest;

final class TaskChecklistOrderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return TaskInput::fields($this);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'item_ids' => ['present', 'array', 'list'],
            'item_ids.*' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
        ];
    }
}
