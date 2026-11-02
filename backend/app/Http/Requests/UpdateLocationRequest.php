<?php

namespace App\Http\Requests;

class UpdateLocationRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'code' => ['sometimes', 'required', ...$this->identifierRules()],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
