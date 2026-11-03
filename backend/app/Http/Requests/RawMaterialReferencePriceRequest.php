<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RawMaterialReferencePriceRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'event_type' => ['required', Rule::in(['PRICE_SET', 'PRICE_WITHDRAWN'])],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'net_pln_per_base_unit' => ['required_if:event_type,PRICE_SET', 'prohibited_if:event_type,PRICE_WITHDRAWN', 'string', 'regex:/^\\d{1,17}(?:\\.\\d{1,14})?$/D'],
            'source_offer_event_id' => ['sometimes', 'nullable', 'uuid'],
            'expected_event_no' => ['required', 'integer', 'min:0'],
            'reason_code' => ['required', 'string', 'max:80'],
        ];
    }
}
