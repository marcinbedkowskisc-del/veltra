<?php

namespace App\Http\Requests;

class ManagingKitchenRequest extends InstallationRequest
{
    public function rules(): array
    {
        return ['managing_kitchen_id' => ['required', 'uuid', 'exists:locations,id']];
    }
}
