<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ListRawMaterialsRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'category_id' => ['sometimes', 'uuid'],
            'lifecycle_state' => ['sometimes', Rule::in(['DRAFT', 'VERIFICATION', 'ACTIVE', 'BLOCKED', 'ARCHIVED'])],
            'sort' => ['sometimes', Rule::in(['code', 'name', 'lifecycle_state'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
