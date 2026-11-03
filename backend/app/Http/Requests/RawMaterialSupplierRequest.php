<?php

namespace App\Http\Requests;

class RawMaterialSupplierRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'supplier_code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/D'],
            'display_name' => ['required', 'string', 'max:200'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:240'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:80'],
        ];
    }
}
