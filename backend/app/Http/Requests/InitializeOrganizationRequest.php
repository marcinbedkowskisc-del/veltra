<?php

namespace App\Http\Requests;

class InitializeOrganizationRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'code' => ['required', ...$this->identifierRules()],
        ];
    }
}
