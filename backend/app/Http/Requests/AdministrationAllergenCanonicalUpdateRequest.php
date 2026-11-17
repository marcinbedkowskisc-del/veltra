<?php

namespace App\Http\Requests;

class AdministrationAllergenCanonicalUpdateRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'canonical_pl' => ['required', 'string', 'max:160'],
            'synonyms_pl' => ['sometimes', 'array', 'max:20'],
            'synonyms_pl.*' => ['string', 'max:160'],
        ];
    }
}
