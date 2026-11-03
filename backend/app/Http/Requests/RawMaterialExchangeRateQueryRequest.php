<?php

namespace App\Http\Requests;

class RawMaterialExchangeRateQueryRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/D'],
            'operation_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
