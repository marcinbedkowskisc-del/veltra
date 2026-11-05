<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class RawMaterialStorageVariantsRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'variants' => ['present', 'array', 'max:30'],
            'variants.*.variant_code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]{1,40}$/D', 'distinct'],
            'variants.*.name_pl' => ['required', 'string', 'max:160'],
            'variants.*.storage_type' => ['required', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_-]{0,31}$/D'],
            'variants.*.temperature_min_c' => ['required', 'numeric', 'between:-273.15,1000'],
            'variants.*.temperature_max_c' => ['required', 'numeric', 'between:-273.15,1000'],
            'variants.*.humidity_min_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'variants.*.humidity_max_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'variants.*.conditions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'variants.*.is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $variants = $this->input('variants', []);
            if (! is_array($variants)) {
                return;
            }
            $defaultCount = 0;

            foreach ($variants as $index => $variant) {
                if (isset($variant['temperature_min_c'], $variant['temperature_max_c'])
                    && (float) $variant['temperature_min_c'] > (float) $variant['temperature_max_c']) {
                    $validator->errors()->add("variants.{$index}.temperature_max_c", __('messages.raw_material_storage_temperature_bounds_invalid'));
                }

                $humidityMin = $variant['humidity_min_percent'] ?? null;
                $humidityMax = $variant['humidity_max_percent'] ?? null;
                if (($humidityMin === null) !== ($humidityMax === null)) {
                    $validator->errors()->add("variants.{$index}.humidity_max_percent", __('messages.raw_material_storage_humidity_bounds_invalid'));
                } elseif ($humidityMin !== null && (float) $humidityMin > (float) $humidityMax) {
                    $validator->errors()->add("variants.{$index}.humidity_max_percent", __('messages.raw_material_storage_humidity_bounds_invalid'));
                }

                if (($variant['is_default'] ?? false) === true || ($variant['is_default'] ?? null) === 1 || ($variant['is_default'] ?? null) === '1') {
                    $defaultCount++;
                }
            }

            if ($defaultCount > 1) {
                $validator->errors()->add('variants', __('messages.raw_material_storage_only_one_default'));
            }
        }];
    }
}
