<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AdministrationSupplierListRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])],
            'sort' => ['sometimes', Rule::in(['display_name', 'legal_name', 'tax_identifier', 'city', 'primary_contact', 'status'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', Rule::in([25, 50, 100])],
        ];
    }
}
