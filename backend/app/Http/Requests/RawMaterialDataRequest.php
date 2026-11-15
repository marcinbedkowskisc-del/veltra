<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RawMaterialDataRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'base_unit_code' => ['required', 'string', Rule::in(['mg', 'g', 'kg', 'ml', 'l', 'pc'])],
            'nominal_quantity' => ['sometimes', 'required', 'string', 'regex:/^\\d{1,18}(?:\\.\\d{1,6})?$/D'],
            'usable_quantity' => ['sometimes', 'required', 'string', 'regex:/^\\d{1,18}(?:\\.\\d{1,6})?$/D'],
            'pricing' => ['sometimes', 'array:basis,amount,unit_code'],
            'pricing.basis' => ['required_with:pricing', 'string', Rule::in(['PACKAGE', 'NOMINAL_UNIT'])],
            'pricing.amount' => ['required_with:pricing', 'string', 'regex:/^\\d{1,17}(?:\\.\\d{1,14})?$/D'],
            'pricing.unit_code' => ['required_if:pricing.basis,NOMINAL_UNIT', 'prohibited_if:pricing.basis,PACKAGE', 'string', Rule::in(['kg', 'l', 'pc'])],
            'supplier_ids' => ['sometimes', 'array', 'max:100'],
            'supplier_ids.*' => ['required', 'uuid', 'distinct'],
            'sku_reservation_id' => [$this->isMethod('POST') ? 'sometimes' : 'prohibited', 'uuid'],
            'aliases' => ['sometimes', 'array', 'max:50'],
            'aliases.*' => ['required', 'string', 'max:200', 'distinct'],
            'conversions' => ['sometimes', 'array', 'max:20'],
            'conversions.*.unit_code' => ['required', 'string', Rule::in(['mg', 'g', 'kg', 'ml', 'l', 'pc']), 'distinct'],
            'conversions.*.to_base_factor' => ['sometimes', 'required', 'regex:/^\\d+(?:\\.\\d{1,12})?$/D'],
            'conversions.*.basis' => ['sometimes', 'required', 'string', 'max:500'],
            'change_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'allergen_declaration' => ['sometimes', 'required', 'array:status,catalog_version_id,items'],
            'document_upload_ids' => ['sometimes', 'array'],
            'document_upload_ids.*' => ['required', 'uuid', 'distinct'],
            'detach_document_version_ids' => ['sometimes', 'array'],
            'detach_document_version_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
