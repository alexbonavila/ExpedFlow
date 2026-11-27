<?php

namespace App\Http\Requests;

use App\Support\AuthorizationInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class ExpedientRequest extends FormRequest
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

    /** @return array<string, list<string|Closure>> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? ['required'] : ['sometimes', 'required'];
        $reference = [...$required, 'integer:strict', 'min:1'];

        return [
            'name' => ['bail', ...$required, 'string', 'max:255'],
            'expedient_type_id' => $reference,
            'requester_client_id' => $reference,
            'status_id' => $reference,
            'represented_client_id' => ['nullable', 'integer:strict', 'min:1'],
            'description' => ['bail', 'nullable', 'string', function (string $attribute, string $value, Closure $fail): void {
                if (strlen($value) > 65_535) {
                    $fail('identity.case_description_too_long')->translate();
                }
            }],
            'template_id' => ['missing'],
            'copy_template' => ['missing'],
            'tag_ids' => ['missing'],
            'tags' => ['missing'],
            'move' => ['missing'],
            'preferences' => ['missing'],
            'uploads' => ['missing'],
        ];
    }
}
