<?php

namespace App\Domain\RawMaterials;

final class RawMaterialCatalogCsvParser
{
    private const LEGACY_HEADER = [
        'sku',
        'entity_type',
        'name',
        'description',
        'base_unit_code',
        'category_code',
    ];

    private const HEADER = [
        ...self::LEGACY_HEADER,
        'nominal_quantity',
        'usable_quantity',
    ];

    public function parse(string $contents): array
    {
        if ($contents === '' || preg_match('//u', $contents) !== 1) {
            throw new RawMaterialException('RAW_MATERIAL_CATALOG_CSV_ENCODING_INVALID', 'Plik CSV jest pusty albo nie zawiera poprawnego UTF-8.', 422);
        }
        if (strlen($contents) > (int) config('raw_material_csv.catalog_max_file_bytes')) {
            throw new RawMaterialException('RAW_MATERIAL_CATALOG_CSV_TOO_LARGE', 'Plik CSV przekracza dozwolony rozmiar.', 413);
        }

        $records = $this->records($contents);
        if ($records === [] || ! in_array($records[0], [self::LEGACY_HEADER, self::HEADER], true)) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CATALOG_CSV_HEADER_INVALID',
                'Nagłówek CSV musi zawierać sześć kolumn katalogu oraz opcjonalne kolumny ilości we właściwej kolejności.',
                422,
                ['row_number' => 1],
            );
        }
        $hasQuantities = $records[0] === self::HEADER;
        if (count($records) < 2) {
            throw new RawMaterialException('RAW_MATERIAL_CATALOG_CSV_EMPTY', 'Plik CSV nie zawiera żadnych wierszy danych.', 422);
        }

        $dataRecords = array_slice($records, 1);
        if (count($dataRecords) > (int) config('raw_material_csv.catalog_max_rows')) {
            throw new RawMaterialException('RAW_MATERIAL_CATALOG_CSV_TOO_MANY_ROWS', 'Plik CSV przekracza dozwoloną liczbę wierszy.', 422);
        }

        $rows = [];
        $skuRows = [];
        foreach ($dataRecords as $index => $fields) {
            $rowNumber = $index + 2;
            if (count($fields) !== count($records[0])) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CATALOG_CSV_COLUMN_COUNT_INVALID',
                    "Wiersz {$rowNumber} nie zawiera wymaganej liczby pól dla nagłówka CSV.",
                    422,
                    ['row_number' => $rowNumber],
                );
            }

            [$sku, $entityType, $name, $description, $baseUnitCode, $categoryCode] = array_slice($fields, 0, 6);
            $rows[] = [
                'row_number' => $rowNumber,
                'sku' => $sku,
                'entity_type' => $entityType,
                'name' => $name,
                'description' => $description === '' ? null : $description,
                'base_unit_code' => $baseUnitCode,
                'category_code' => $categoryCode,
                'nominal_quantity' => $hasQuantities && $fields[6] !== '' ? $fields[6] : null,
                'usable_quantity' => $hasQuantities && $fields[7] !== '' ? $fields[7] : null,
                'usable_quantity_provided' => $hasQuantities && $fields[7] !== '',
                'duplicate_row_numbers' => [],
            ];
            if ($sku !== '') {
                $skuRows[$sku][] = $rowNumber;
            }
        }

        foreach ($rows as &$row) {
            if (count($skuRows[$row['sku']] ?? []) > 1) {
                $row['duplicate_row_numbers'] = $skuRows[$row['sku']];
            }
        }
        unset($row);

        return $rows;
    }

    private function records(string $contents): array
    {
        $records = [];
        $record = [];
        $field = '';
        $quoted = false;
        $afterQuote = false;
        $atFieldStart = true;
        $recordTouched = false;
        $recordLine = 1;
        $length = strlen($contents);

        for ($index = 0; $index < $length; $index++) {
            $character = $contents[$index];
            if ($quoted) {
                if ($character === '"') {
                    if ($index + 1 < $length && $contents[$index + 1] === '"') {
                        $field .= '"';
                        $index++;
                    } else {
                        $quoted = false;
                        $afterQuote = true;
                    }
                } else {
                    $field .= $character;
                }

                continue;
            }

            if ($afterQuote && ! in_array($character, [';', "\r", "\n"], true)) {
                $this->malformed($recordLine);
            }
            if ($character === '"' && $atFieldStart && ! $afterQuote) {
                $quoted = true;
                $atFieldStart = false;
                $recordTouched = true;

                continue;
            }
            if ($character === '"') {
                $this->malformed($recordLine);
            }
            if ($character === ';') {
                $record[] = $field;
                $field = '';
                $atFieldStart = true;
                $afterQuote = false;
                $recordTouched = true;

                continue;
            }
            if ($character === "\r" || $character === "\n") {
                if ($character === "\r" && ($index + 1 >= $length || $contents[$index + 1] !== "\n")) {
                    $this->malformed($recordLine);
                }
                if ($character === "\r") {
                    $index++;
                }
                $record[] = $field;
                $records[] = $record;
                $record = [];
                $field = '';
                $atFieldStart = true;
                $afterQuote = false;
                $recordTouched = false;
                $recordLine = count($records) + 1;

                continue;
            }
            if ($afterQuote) {
                $this->malformed($recordLine);
            }

            $field .= $character;
            $atFieldStart = false;
            $recordTouched = true;
        }

        if ($quoted) {
            $this->malformed($recordLine);
        }
        if ($recordTouched || $field !== '' || $record !== [] || $afterQuote) {
            $record[] = $field;
            $records[] = $record;
        }

        return $records;
    }

    private function malformed(int $rowNumber): never
    {
        throw new RawMaterialException(
            'RAW_MATERIAL_CATALOG_CSV_MALFORMED',
            "Wiersz {$rowNumber} zawiera nieprawidłowe cudzysłowy albo zakończenia linii CSV.",
            422,
            ['row_number' => $rowNumber],
        );
    }
}
