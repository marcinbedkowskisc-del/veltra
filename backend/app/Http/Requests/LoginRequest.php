<?php

namespace App\Http\Requests;

use App\Models\User;

class LoginRequest extends InstallationRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normalizeEmail($this->input('email'))]);
        }
        parent::prepareForValidation();
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:256'],
        ];
    }
}
