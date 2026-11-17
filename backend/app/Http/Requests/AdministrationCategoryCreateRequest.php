<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AdministrationCategoryCreateRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'code' => [
                'bail', 'required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/D',
                Rule::unique('raw_material_categories', 'code')
                    ->where('organization_id', $this->user()->organization_id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ];
    }
}
