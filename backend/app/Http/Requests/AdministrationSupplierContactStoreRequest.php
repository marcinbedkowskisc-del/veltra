<?php

namespace App\Http\Requests;

class AdministrationSupplierContactStoreRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:200', 'not_regex:/^\s*$/u'],
            'position' => ['sometimes', 'nullable', 'string', 'max:120', 'not_regex:/^\s*$/u'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40', 'not_regex:/^\s*$/u'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
