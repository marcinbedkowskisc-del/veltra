<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AdministrationAllergenEntryUpdateRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'source_name' => ['required', 'string', 'max:180'],
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
