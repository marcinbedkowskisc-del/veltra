<?php

namespace App\Http\Requests;

class OrganizationCapabilityActivationRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'expected_state_version' => ['required', 'integer', 'min:0'],
            'current_password' => ['required', 'string', 'max:256'],
        ];
    }
}
