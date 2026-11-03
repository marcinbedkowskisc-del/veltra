<?php

namespace App\Http\Requests;

class RawMaterialExchangeRateOverrideRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'pln_per_quote_unit' => ['required', 'string', 'regex:/^\\d{1,12}(?:\\.\\d{1,12})?$/D'],
            'reason_code' => ['required', 'string', 'max:80'],
        ];
    }
}
