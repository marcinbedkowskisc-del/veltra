<?php

namespace App\Http\Requests;

final class AdministrationUserProfileRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
        ];
    }
}
