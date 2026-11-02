<?php

namespace App\Http\Requests;

class RawMaterialReasonRequest extends InstallationRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }
}
