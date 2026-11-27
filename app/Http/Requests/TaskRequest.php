<?php

namespace App\Http\Requests;

use App\Support\TaskInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class TaskRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $input = TaskInput::fields($this);
        if (is_string($input['name'] ?? null)) {
            $input['name'] = Str::trim($input['name']);
        }

        return $input;
    }

    /** @return array<string, list<string|\Closure>> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? ['required'] : ['sometimes', 'required'];

        $input = $this->validationData();
        $date = isset($input['scheduled_date']) ? 'required' : 'nullable';
        $time = isset($input['scheduled_time']) ? 'required' : 'nullable';

        return [
            'name' => ['bail', ...$required, 'string', 'max:255'],
            'description' => ['bail', 'nullable', 'string', TaskInput::textLimit(...)],
            'status_id' => [...$required, 'integer:strict', 'min:1'],
            'scheduled_date' => ['bail', $date, 'string', TaskInput::civilDate(...)],
            'scheduled_time' => ['bail', $time, 'string', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/'],
            'assignees' => ['missing'],
            'user_ids' => ['missing'],
            'start_date' => ['missing'],
            'end_date' => ['missing'],
        ];
    }
}
