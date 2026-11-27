<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ExpedientIndexRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'status_id' => ['sometimes', 'integer', 'min:1'],
            'expedient_type_id' => ['sometimes', 'integer', 'min:1'],
            'requester_client_id' => ['sometimes', 'integer', 'min:1'],
            'represented_client_id' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', 'in:code,name,created_at,column_position'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ];
    }
}
