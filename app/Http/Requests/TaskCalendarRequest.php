<?php

namespace App\Http\Requests;

use App\Support\TaskInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class TaskCalendarRequest extends FormRequest
{
    /** @return array<int|string, mixed> */
    public function validationData(): array
    {
        parse_str($this->server->getString('QUERY_STRING'), $input);

        return $input;
    }

    /** @return array<string, list<string|Closure>> */
    public function rules(): array
    {
        $date = ['bail', 'sometimes', 'required', 'string', TaskInput::civilDate(...)];

        return [
            'from' => $date,
            'to' => $date,
            'user_id' => ['missing'],
            'owner_id' => ['missing'],
            'user' => ['missing'],
            'owner' => ['missing'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $input = $validator->getData();
            if ($validator->errors()->isEmpty() && isset($input['from'], $input['to']) && $input['from'] > $input['to']) {
                $validator->errors()->add('to', __('tasks.task_calendar_range_invalid'));
            }
        }];
    }
}
