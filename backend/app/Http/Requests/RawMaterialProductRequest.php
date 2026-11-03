<?php

namespace App\Http\Requests;

class RawMaterialProductRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'uuid'],
            'raw_material_revision_id' => ['required', 'uuid'],
            'product_name' => ['required', 'string', 'max:200'],
            'supplier_sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gtin' => ['sometimes', 'nullable', 'regex:/^[0-9]{8,14}$/D'],
            'purchase_unit_code' => ['required', 'string', 'max:24', 'regex:/^[A-Z][A-Z0-9_-]{0,23}$/D'],
            'content_quantity' => ['required', 'string', 'regex:/^\\d{1,15}(?:\\.\\d{1,9})?$/D'],
            'content_unit_code' => ['required', 'in:mg,g,kg,ml,l,pc'],
        ];
    }
}
