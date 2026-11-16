<?php

namespace App\Http\Requests;

class RawMaterialPricingPreviewRequest extends InstallationRequest
{
    public function rules(): array
    {
        $rules = (new RawMaterialDataRequest)->rules();
        $rules['nominal_quantity'][0] = 'required';
        $rules['pricing'][0] = 'required';

        return array_intersect_key($rules, array_flip([
            'base_unit_code', 'nominal_quantity', 'usable_quantity',
            'pricing', 'pricing.basis', 'pricing.amount', 'pricing.unit_code',
        ]));
    }
}
