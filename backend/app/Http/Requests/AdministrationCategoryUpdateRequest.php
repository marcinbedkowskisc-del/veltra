<?php

namespace App\Http\Requests;

class AdministrationCategoryUpdateRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ];
    }
}
