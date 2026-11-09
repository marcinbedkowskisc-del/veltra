<?php

namespace App\Domain\RawMaterials;

final class RawMaterialVersion
{
    public static function assertCurrent(object $material, ?string $expected): void
    {
        if ($expected !== null && preg_match('/^"([0-9a-f-]{36})"$/D', $expected, $matches)) {
            $expected = $matches[1];
        }
        if ($expected === null || $expected === '') {
            throw new RawMaterialException('RAW_MATERIAL_VERSION_REQUIRED', 'Wymagana jest wersja odczytana przed rozpoczęciem edycji.', 428);
        }
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $expected)) {
            throw new RawMaterialException('RAW_MATERIAL_VERSION_INVALID', 'Nieprawidłowy identyfikator wersji edycji.', 422);
        }
        if (! hash_equals($material->edit_version, $expected)) {
            throw new RawMaterialException('RAW_MATERIAL_VERSION_CONFLICT', 'Surowiec został zmieniony. Wczytaj aktualną wersję; lokalne zmiany zostaną utracone.', 409);
        }
    }
}
