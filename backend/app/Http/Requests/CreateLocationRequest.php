<?php

namespace App\Http\Requests;

use App\Domain\Installation\LocationType;
use Illuminate\Validation\Rule;

class CreateLocationRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'code' => ['required', ...$this->identifierRules()],
            'type' => ['required', Rule::enum(LocationType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
