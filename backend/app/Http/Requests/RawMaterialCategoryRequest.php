<?php

namespace App\Http\Requests;

class RawMaterialCategoryRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/D'],
            'name' => ['required', 'string', 'max:160'],
        ];
    }
}
