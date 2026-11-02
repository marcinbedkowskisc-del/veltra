<?php

namespace App\Http\Requests;

class SupplyRequest extends InstallationRequest
{
    public function rules(): array
    {
        return ['supplier_kitchen_id' => ['required', 'uuid', 'exists:locations,id']];
    }
}
