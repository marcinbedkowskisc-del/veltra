<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class ModuleConfigurationRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'module_ids' => ['required', 'array', 'min:1', 'max:20'],
            'module_ids.*' => ['required', 'string', 'max:80', 'regex:/^[A-Z][A-Z0-9_]{0,79}$/D', 'distinct'],
            'location_ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'location_ids.*' => ['required', 'uuid', 'distinct'],
            'all_compatible_locations' => ['sometimes', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'current_password' => ['required', 'string', 'max:256'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('all_compatible_locations') === array_key_exists('location_ids', $this->all())) {
                    $validator->errors()->add('location_ids', __('messages.select_locations_or_all'));
                }
            },
        ];
    }
}
