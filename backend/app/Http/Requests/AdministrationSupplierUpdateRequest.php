<?php

namespace App\Http\Requests;

class AdministrationSupplierUpdateRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'required', 'string', 'max:240', 'not_regex:/^\s*$/u'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:/^\s*$/u'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/[\p{L}\p{N}]/u'],
            'street_address' => ['sometimes', 'nullable', 'string', 'max:240', 'not_regex:/^\s*$/u'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120', 'not_regex:/^\s*$/u'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40', 'not_regex:/^\s*$/u'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if ($this->all() === []) {
            abort(422, 'Wprowadź przynajmniej jedną zmianę.');
        }
    }
}
