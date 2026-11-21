<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class ListBossRawMaterialsRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'category_id' => ['sometimes', 'uuid'],
            'unit' => ['sometimes', 'string', 'max:24', 'regex:/^[a-z][a-z0-9_]{0,23}$/D'],
            'sort' => ['sometimes', Rule::in(['name', 'code', 'category', 'unit'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', Rule::in([25, 50, 100])],
        ];
    }
}
