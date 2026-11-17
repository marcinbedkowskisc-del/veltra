<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AdministrationUnitListRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])],
            'dimension' => ['sometimes', Rule::in(['MASS', 'VOLUME', 'COUNT'])],
        ];
    }
}
