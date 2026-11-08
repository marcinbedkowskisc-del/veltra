<?php

namespace App\Http\Requests;

use Illuminate\Validation\ValidationException;

class RawMaterialSupplierCorrectionRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', 'string', 'max:200', 'not_regex:/^\\s*$/u'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:240', 'not_regex:/^\\s*$/u'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:80', 'not_regex:/^\\s*$/u'],
            'reason' => ['required', 'string', 'min:3', 'max:500', 'not_regex:/^\\s*$/u'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (! array_intersect(['display_name', 'legal_name', 'tax_identifier'], array_keys($this->all()))) {
            throw ValidationException::withMessages([
                'request' => [__('messages.raw_material_supplier_correction_required')],
            ]);
        }
    }
}
