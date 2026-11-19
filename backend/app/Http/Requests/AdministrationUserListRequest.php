<?php

namespace App\Http\Requests;

use App\Domain\Authorization\BusinessRole;
use Illuminate\Validation\Rule;

final class AdministrationUserListRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', 'nullable', Rule::enum(BusinessRole::class)],
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'all'])],
            'sort' => ['sometimes', Rule::in(['name', 'email', 'status'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', Rule::in([25, 50, 100])],
        ];
    }
}
