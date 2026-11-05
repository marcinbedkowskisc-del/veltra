<?php

namespace App\Http\Requests;

class RawMaterialCsvUploadRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) ceil(config('raw_material_csv.max_file_bytes') / 1024)],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Wybierz plik CSV do podglądu.',
            'file.file' => 'Przesłany plik CSV jest nieprawidłowy.',
            'file.max' => 'Plik CSV przekracza dozwolony rozmiar.',
        ];
    }
}
