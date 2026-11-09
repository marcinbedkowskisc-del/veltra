<?php

namespace App\Http\Requests;

class RawMaterialBulkShareMaterialsRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid'],
            'material_ids' => ['required', 'array', 'min:1', 'max:100'],
            'material_ids.*' => ['required', 'uuid'],
        ];
    }
}
