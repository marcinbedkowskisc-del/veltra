<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RawMaterialDataRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'base_unit_code' => ['required', 'string', Rule::in(['mg', 'g', 'kg', 'ml', 'l', 'pc'])],
            'aliases' => ['sometimes', 'array', 'max:50'],
            'aliases.*' => ['required', 'string', 'max:200', 'distinct'],
            'conversions' => ['sometimes', 'array', 'max:20'],
            'conversions.*.unit_code' => ['required', 'string', Rule::in(['mg', 'g', 'kg', 'ml', 'l', 'pc']), 'distinct'],
            'conversions.*.to_base_factor' => ['sometimes', 'required', 'regex:/^\\d+(?:\\.\\d{1,12})?$/D'],
            'conversions.*.basis' => ['sometimes', 'required', 'string', 'max:500'],
            'change_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
