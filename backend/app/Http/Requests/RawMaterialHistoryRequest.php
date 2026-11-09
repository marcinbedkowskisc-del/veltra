<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RawMaterialHistoryRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100, 200])],
        ];
    }
}
