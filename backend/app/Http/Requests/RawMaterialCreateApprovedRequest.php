<?php

namespace App\Http\Requests;

class RawMaterialCreateApprovedRequest extends RawMaterialDataRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'sku_reservation_id' => ['required', 'uuid'],
            'allergen_declaration' => ['required', 'array:status,catalog_version_id,items'],
        ];
    }
}
