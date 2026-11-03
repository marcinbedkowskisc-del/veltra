<?php

namespace App\Http\Requests;

class RawMaterialRestatementRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'restated_net_pln_per_base_unit' => ['required', 'string', 'regex:/^\\d{1,17}(?:\\.\\d{1,14})?$/D'],
            'operation_date' => ['required', 'date_format:Y-m-d'],
            'reason_code' => ['required', 'string', 'max:80'],
        ];
    }
}
