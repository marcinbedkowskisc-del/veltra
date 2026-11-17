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
            'supplier_id' => ['sometimes', 'uuid'],
            'unit' => ['sometimes', 'string', 'max:24', 'regex:/^[a-z][a-z0-9_]{0,23}$/D', Rule::exists('raw_material_units', 'code')],
            'lifecycle_state' => ['sometimes', Rule::in(['DRAFT', 'VERIFICATION', 'ACTIVE', 'BLOCKED', 'ARCHIVED'])],
            'sort' => ['sometimes', Rule::in(['code', 'sku', 'name', 'unit', 'nominal_quantity', 'usable_quantity', 'reference_net_price', 'usable_unit_cost', 'lifecycle_state'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100, 200])],
        ];
    }
}
