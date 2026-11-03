<?php

namespace App\Http\Requests;

class RawMaterialOfferRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'offer_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
