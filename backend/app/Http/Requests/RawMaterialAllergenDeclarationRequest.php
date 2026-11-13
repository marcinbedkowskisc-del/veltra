<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RawMaterialAllergenDeclarationRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['UNKNOWN', 'DECLARED', 'NO_KNOWN_ALLERGENS'])],
            'catalog_version_id' => ['nullable', 'required_if:status,DECLARED', 'required_if:status,NO_KNOWN_ALLERGENS', 'uuid'],
            'items' => ['sometimes', 'array', 'max:80'],
            'items.*' => ['array:allergen_code,exposure_kind'],
            'items.*.allergen_code' => ['required', 'string', 'max:40', 'regex:/^[A-Z][A-Z0-9_-]{0,39}$/D', 'distinct'],
            'items.*.exposure_kind' => ['required', Rule::in(['DIRECT', 'CROSS_CONTACT_POTENTIAL'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $status = $this->input('status');
            $items = $this->input('items', []);
            $catalogVersionId = $this->input('catalog_version_id');

            if ($status === 'UNKNOWN' && ($catalogVersionId !== null || $items !== [])) {
                $validator->errors()->add('status', __('messages.raw_material_allergen_unknown_must_be_explicit'));
            } elseif ($status === 'DECLARED' && $items === []) {
                $validator->errors()->add('items', __('messages.raw_material_allergen_declaration_required'));
            } elseif ($status === 'NO_KNOWN_ALLERGENS' && $items !== []) {
                $validator->errors()->add('items', __('messages.raw_material_allergen_no_known_items_forbidden'));
            }
        }];
    }
}
