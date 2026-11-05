<?php

namespace App\Http\Requests;

use Illuminate\Validation\ValidationException;

class RawMaterialCsvCommitRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'boolean'],
        ];
    }

    public function confirmed(): bool
    {
        if ($this->validated('confirmed') !== true) {
            throw ValidationException::withMessages([
                'confirmed' => ['Aby zatwierdzić import, ustaw pole confirmed na true.'],
            ]);
        }

        return true;
    }
}
