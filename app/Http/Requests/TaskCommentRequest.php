<?php

namespace App\Http\Requests;

use App\Support\AuthorizationInput;
use App\Support\TaskInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class TaskCommentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return AuthorizationInput::fields($this);
    }

    /** @return array<string, list<string|Closure>> */
    public function rules(): array
    {
        return ['body' => ['bail', 'required', 'string', function (string $attribute, string $value, Closure $fail): void {
            if (Str::trim($value) === '') {
                $fail('validation.required')->translate(['attribute' => $attribute]);
            }
        }, TaskInput::textLimit(...)]];
    }
}
