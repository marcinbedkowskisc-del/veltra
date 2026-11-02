<?php

namespace App\Http\Requests;

class RawMaterialCategoryRenameRequest extends InstallationRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160']];
    }
}
