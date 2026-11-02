<?php

namespace App\Http\Requests;

class SelectOperatingLocationRequest extends InstallationRequest
{
    public function rules(): array
    {
        return ['location_id' => ['required', 'uuid']];
    }
}
