<?php

namespace App\Http\Requests;

class AdministrationSupplierSimilarNamesRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'official_name' => ['required', 'string', 'max:240', 'not_regex:/^\s*$/u'],
            'exclude_supplier_id' => ['sometimes', 'uuid'],
        ];
    }
}
