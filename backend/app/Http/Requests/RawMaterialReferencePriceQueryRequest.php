<?php

namespace App\Http\Requests;

class RawMaterialReferencePriceQueryRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
