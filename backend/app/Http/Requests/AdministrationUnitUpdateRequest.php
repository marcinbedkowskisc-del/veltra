<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AdministrationUnitUpdateRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['required', 'string', 'max:24'],
            'dimension' => ['required', Rule::in(['MASS', 'VOLUME', 'COUNT'])],
            'base_unit_code' => ['required', 'string', 'max:24', 'regex:/^[a-z][a-z0-9_]{0,23}$/D'],
            'to_base_factor' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,12})?$/D'],
        ];
    }
}
