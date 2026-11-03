<?php

namespace App\Http\Requests;

class RawMaterialProductRevisionRequest extends RawMaterialProductRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'expected_revision_id' => ['required', 'uuid'],
        ];
    }
}
