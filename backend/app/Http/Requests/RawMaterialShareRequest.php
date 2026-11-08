<?php

namespace App\Http\Requests;

class RawMaterialShareRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid'],
        ];
    }
}
