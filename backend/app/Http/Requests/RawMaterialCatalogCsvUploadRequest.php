<?php

namespace App\Http\Requests;

class RawMaterialCatalogCsvUploadRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) ceil(config('raw_material_csv.catalog_max_file_bytes') / 1024)],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Wybierz plik CSV katalogu do podglądu.',
            'file.file' => 'Przesłany plik CSV katalogu jest nieprawidłowy.',
            'file.max' => 'Plik CSV katalogu przekracza dozwolony rozmiar.',
        ];
    }
}
