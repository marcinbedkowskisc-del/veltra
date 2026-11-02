<?php

namespace App\Http\Requests;

use App\Domain\RawMaterials\RawMaterialPermission;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RawMaterialGrantRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'permission' => ['required', Rule::enum(RawMaterialPermission::class)],
            'reason' => ['required', 'string', 'min:3', 'max:80'],
            'valid_from' => ['sometimes', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $from = $this->date('valid_from');
            $until = $this->date('valid_until');
            if ($from && $until && $until->lessThanOrEqualTo($from)) {
                $validator->errors()->add('valid_until', __('messages.business_end_after_start'));
            }
        }];
    }
}
