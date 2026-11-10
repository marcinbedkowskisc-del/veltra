<?php

namespace App\Http\Requests;

class RawMaterialBulkArchiveRequest extends InstallationRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:id,version'],
            'items.*.id' => ['required', 'uuid', 'distinct'],
            'items.*.version' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
