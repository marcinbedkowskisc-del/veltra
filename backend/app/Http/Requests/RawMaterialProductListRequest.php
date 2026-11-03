<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RawMaterialProductListRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'uuid'],
            'lifecycle_state' => ['sometimes', Rule::in(['ACTIVE', 'ARCHIVED'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
