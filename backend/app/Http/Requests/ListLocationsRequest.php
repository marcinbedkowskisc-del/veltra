<?php

namespace App\Http\Requests;

use App\Domain\Installation\LocationType;
use Illuminate\Validation\Rule;

class ListLocationsRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', Rule::enum(LocationType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
