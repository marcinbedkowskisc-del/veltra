<?php

namespace App\Http\Requests;

class RawMaterialBulkShareLocationsRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'location_ids' => ['required', 'array', 'min:1', 'max:100'],
            'location_ids.*' => ['required', 'uuid'],
        ];
    }
}
