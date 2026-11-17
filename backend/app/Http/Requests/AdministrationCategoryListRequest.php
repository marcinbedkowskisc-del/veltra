<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AdministrationCategoryListRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', Rule::in([25, 50, 100])],
        ];
    }
}
