<?php

namespace App\Http\Requests;

class RawMaterialShareRevocationRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
