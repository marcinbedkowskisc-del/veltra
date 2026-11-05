<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RawMaterialCompatibilityAssessmentRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'document_version_id' => ['required', 'uuid'],
            'outcome' => ['required', Rule::in(['COMPATIBLE', 'INCOMPATIBLE'])],
            'decision_reason' => ['required', 'string', 'min:20', 'max:2000'],
            'allergen_declaration_reviewed' => ['required', 'accepted'],
        ];
    }
}
