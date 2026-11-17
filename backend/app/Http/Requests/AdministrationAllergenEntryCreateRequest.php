<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AdministrationAllergenEntryCreateRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'canonical_allergen_id' => ['nullable', 'required_without:new_canonical', 'prohibited_with:new_canonical', 'string', 'max:40', 'regex:/^[A-Z][A-Z0-9_]{0,39}$/D'],
            'new_canonical' => ['nullable', 'required_without:canonical_allergen_id', 'array:id,parent_id,canonical_pl,synonyms_pl,original_names'],
            'new_canonical.id' => ['required_with:new_canonical', 'string', 'max:40', 'regex:/^[A-Z][A-Z0-9_]{0,39}$/D'],
            'new_canonical.parent_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z][A-Z0-9_]{0,39}$/D'],
            'new_canonical.canonical_pl' => ['required_with:new_canonical', 'string', 'max:160'],
            'new_canonical.synonyms_pl' => ['nullable', 'array', 'max:20'],
            'new_canonical.synonyms_pl.*' => ['string', 'max:160'],
            'new_canonical.original_names' => ['nullable', 'array'],
            'new_canonical.original_names.*' => ['string', 'max:180'],
            'source_name' => ['nullable', 'string', 'max:180'],
            'status' => ['required', Rule::in(['MANDATORY', 'RECOMMENDED', 'VOLUNTARY', 'NATIONAL_OPTION', 'WARNING_ONLY', 'FUTURE_MANDATORY', 'HISTORICAL'])],
            'listing_rule' => ['required', 'string', 'max:64'],
            'legal_effective_from' => ['nullable', 'date'],
            'transition_end' => ['nullable', 'date'],
            'source_scope' => ['required', 'string', 'max:40'],
            'threshold' => ['nullable', 'array:amount,unit,analyte,comparator,alternative'],
            'threshold.amount' => ['required_with:threshold', 'numeric', 'min:0'],
            'threshold.unit' => ['required_with:threshold', 'string', 'max:32'],
            'threshold.analyte' => ['nullable', 'string', 'max:120'],
            'threshold.comparator' => ['nullable', Rule::in(['GT', 'GTE', 'LT', 'LTE', 'EQ'])],
            'threshold.alternative' => ['nullable', 'array:amount,unit,comparator'],
            'threshold.alternative.amount' => ['required_with:threshold.alternative', 'numeric', 'min:0'],
            'threshold.alternative.unit' => ['required_with:threshold.alternative', 'string', 'max:32'],
            'threshold.alternative.comparator' => ['nullable', Rule::in(['GT', 'GTE', 'LT', 'LTE', 'EQ'])],
            'exceptions' => ['sometimes', 'array', 'max:40'],
            'exceptions.*' => ['string', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
