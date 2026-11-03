<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RawMaterialOfferPriceRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'event_type' => ['required', Rule::in(['PRICE_SET', 'WITHDRAWN'])],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:valid_from'],
            'net_amount_per_purchase_unit' => ['required_if:event_type,PRICE_SET', 'prohibited_if:event_type,WITHDRAWN', 'string', 'regex:/^\\d{1,20}(?:\\.\\d{1,8})?$/D'],
            'currency_code' => ['required_if:event_type,PRICE_SET', 'prohibited_if:event_type,WITHDRAWN', 'string', 'size:3', 'regex:/^[A-Z]{3}$/D'],
            'minimum_order_quantity' => ['sometimes', 'nullable', 'string', 'regex:/^\\d{1,15}(?:\\.\\d{1,9})?$/D'],
            'quantity_from' => ['required_if:event_type,PRICE_SET', 'prohibited_if:event_type,WITHDRAWN', 'string', 'regex:/^\\d{1,15}(?:\\.\\d{1,9})?$/D'],
            'quantity_until' => ['sometimes', 'nullable', 'string', 'regex:/^\\d{1,15}(?:\\.\\d{1,9})?$/D'],
            'source_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'source_reference' => ['sometimes', 'nullable', 'string', 'max:160'],
            'supersedes_event_id' => ['required_if:event_type,WITHDRAWN', 'prohibited_if:event_type,PRICE_SET', 'uuid'],
            'reason_code' => ['required', 'string', 'max:80'],
        ];
    }
}
