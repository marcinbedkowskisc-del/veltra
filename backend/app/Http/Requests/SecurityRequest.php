<?php

namespace App\Http\Requests;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\Permission;
use Illuminate\Validation\Rule;

class SecurityRequest extends InstallationRequest
{
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'grantRole' => [
                'role' => ['required', Rule::enum(BusinessRole::class)], 'location_id' => ['nullable', 'uuid'],
                'current_password' => ['nullable', 'string', 'max:256'],
            ],
            'revokeRole' => ['current_password' => ['nullable', 'string', 'max:256']],
            'accountStatus' => ['is_active' => ['required', 'boolean']],
            'changeEmail' => ['email' => ['required', 'email:rfc', 'max:254']],
            'personnel' => [
                'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email:rfc', 'max:254'],
            ],
            'decide' => ['approve' => ['required', 'boolean']],
            'grantPermission' => [
                'permission' => ['required', Rule::enum(Permission::class)],
                'valid_from' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date'],
            ],
            'revokePermission' => ['permission' => ['required', Rule::enum(Permission::class)]],
            'link' => ['kind' => ['required', Rule::in(['activation', 'reset'])]],
            'activate', 'resetPassword' => [
                'email' => ['required', 'email:rfc', 'max:254'], 'token' => ['required', 'string', 'size:64'],
                'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
                'password_confirmation' => ['required', 'string', 'max:72'],
            ],
            'access' => [
                'location_id' => ['required', 'uuid'], 'permission' => ['required', Rule::enum(Permission::class)],
            ],
            'assignments' => ['location_id' => ['nullable', 'uuid']],
            default => [],
        };
    }
}
