<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RawMaterialSupplierListRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'lifecycle_state' => ['sometimes', Rule::in(['ACTIVE', 'ARCHIVED'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
