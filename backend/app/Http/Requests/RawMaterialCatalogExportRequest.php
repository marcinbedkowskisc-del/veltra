<?php

namespace App\Http\Requests;

use App\Domain\RawMaterials\RawMaterialCsvCatalogExportWorkflow;
use Illuminate\Validation\Rule;

final class RawMaterialCatalogExportRequest extends ListRawMaterialsRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'columns' => ['sometimes', 'array', 'min:1'],
            'columns.*' => ['required', 'string', 'distinct', Rule::in(RawMaterialCsvCatalogExportWorkflow::SELECTABLE_COLUMNS)],
            'material_ids' => ['sometimes', 'array', 'min:1', 'max:'.config('raw_material_csv.export_max_rows')],
            'material_ids.*' => ['required', 'uuid', 'distinct'],
            'page' => ['prohibited'],
            'per_page' => ['prohibited'],
        ]);
    }
}
