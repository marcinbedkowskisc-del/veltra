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
            'net_pln_per_base_unit' => [Rule::requiredIf(fn (): bool => $this->input('event_type') === 'PRICE_SET' && ! $this->has('net_pln_per_package')), 'prohibited_if:event_type,PRICE_WITHDRAWN', 'prohibits:net_pln_per_package', 'string', 'regex:/^\\d{1,17}(?:\\.\\d{1,14})?$/D'],
            'net_pln_per_package' => ['sometimes', 'required', 'prohibited_if:event_type,PRICE_WITHDRAWN', 'prohibits:net_pln_per_base_unit,source_offer_event_id', 'string', 'regex:/^\\d{1,17}(?:\\.\\d{1,14})?$/D'],
            'source_offer_event_id' => ['sometimes', 'nullable', 'uuid'],
            'expected_event_no' => ['required', 'integer', 'min:0'],
            'reason_code' => ['required', 'string', 'max:80'],
        ];
    }
}
