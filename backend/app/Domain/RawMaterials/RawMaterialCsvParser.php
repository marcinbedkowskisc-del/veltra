<?php

namespace App\Domain\RawMaterials;

use DateTimeImmutable;

final class RawMaterialCsvParser
{
    private const HEADER = [
        'ingredient_id',
        'ingredient_name',
        'price_net',
        'vat_rate',
        'unit',
        'effective_date',
    ];

    public function parse(string $contents): array
    {
        if ($contents === '' || preg_match('//u', $contents) !== 1) {
            throw new RawMaterialException('RAW_MATERIAL_CSV_ENCODING_INVALID', 'Plik CSV jest pusty albo nie zawiera poprawnego UTF-8.', 422);
        }
        if (strlen($contents) > config('raw_material_csv.max_file_bytes')) {
            throw new RawMaterialException('RAW_MATERIAL_CSV_TOO_LARGE', 'Plik CSV przekracza dozwolony rozmiar.', 413);
        }

        $records = $this->records($contents);
        if ($records === [] || $records[0]['fields'] !== self::HEADER) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CSV_HEADER_INVALID',
                'Nagłówek CSV musi zawierać dokładnie sześć wymaganych kolumn we właściwej kolejności.',
                422,
                ['row_number' => 1],
            );
        }
        if (count($records) < 2) {
            throw new RawMaterialException('RAW_MATERIAL_CSV_EMPTY', 'Plik CSV nie zawiera żadnych wierszy danych.', 422);
        }

        $rows = [];
        $seen = [];
        foreach (array_slice($records, 1) as $record) {
            $rowNumber = $record['row_number'];
            $fields = $record['fields'];
            if (count($fields) !== count(self::HEADER)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_COLUMN_COUNT_INVALID',
                    "Wiersz {$rowNumber} musi zawierać dokładnie sześć pól.",
                    422,
                    ['row_number' => $rowNumber],
                );
            }
            if (in_array('', $fields, true)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_EMPTY_FIELD',
                    "Wiersz {$rowNumber} zawiera puste pole.",
                    422,
                    ['row_number' => $rowNumber],
                );
            }

            [$ingredientId, $ingredientName, $priceNet, $vatRate, $unit, $effectiveDate] = $fields;
            if (! preg_match('/\ASUR-[0-9]{6,}\z/', $ingredientId)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_IDENTIFIER_INVALID',
                    "Wiersz {$rowNumber} zawiera nieprawidłowy identyfikator SUR.",
                    422,
                    ['row_number' => $rowNumber],
                );
            }
            if (isset($seen[$ingredientId])) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_DUPLICATE_IDENTIFIER',
                    "Identyfikator {$ingredientId} powtarza się w wierszach {$seen[$ingredientId]} i {$rowNumber}.",
                    422,
                    ['row_numbers' => [$seen[$ingredientId], $rowNumber]],
                );
            }
            $seen[$ingredientId] = $rowNumber;

            if (trim($ingredientName) === '' || mb_strlen($ingredientName, 'UTF-8') > 200) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_NAME_INVALID',
                    "Nazwa składnika w wierszu {$rowNumber} jest pusta albo przekracza 200 znaków.",
                    422,
                    ['row_number' => $rowNumber],
                );
            }
            if (! $this->decimalIsValid($priceNet, 14, 17)
                || ! $this->decimalIsValid($vatRate, 2, 3)
                || ! in_array($unit, ['kg', 'l', 'szt.'], true)
                || ! $this->dateIsValid($effectiveDate)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_VALUE_INVALID',
                    "Wiersz {$rowNumber} zawiera cenę, VAT, jednostkę albo datę w nieprawidłowym formacie.",
                    422,
                    ['row_number' => $rowNumber],
                );
            }
            if (! $this->vatIsValid($vatRate)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_VAT_INVALID',
                    "Stawka VAT w wierszu {$rowNumber} musi mieścić się w zakresie od 0 do 100.",
                    422,
                    ['row_number' => $rowNumber],
                );
            }

            $rows[] = [
                'ingredient_id' => $ingredientId,
                'ingredient_name' => $ingredientName,
                'price_net' => $priceNet,
                'vat_rate' => $vatRate,
                'unit' => $unit,
                'effective_date' => $effectiveDate,
            ];
            if (count($rows) > config('raw_material_csv.max_rows')) {
                throw new RawMaterialException('RAW_MATERIAL_CSV_TOO_MANY_ROWS', 'Plik CSV przekracza dozwoloną liczbę wierszy.', 422);
            }
        }

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
        $physicalLine = 1;
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
                    if ($character === "\n") {
                        $physicalLine++;
                    }
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
                $records[] = ['row_number' => count($records) + 1, 'fields' => $record];
                $record = [];
                $field = '';
                $atFieldStart = true;
                $afterQuote = false;
                $recordTouched = false;
                $physicalLine++;
                $recordLine = $physicalLine;

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
            $records[] = ['row_number' => count($records) + 1, 'fields' => $record];
        }

        return $records;
    }

    private function malformed(int $rowNumber): never
    {
        throw new RawMaterialException(
            'RAW_MATERIAL_CSV_QUOTING_INVALID',
            "Składnia cytowania CSV jest niepoprawna w wierszu {$rowNumber}.",
            422,
            ['row_number' => $rowNumber],
        );
    }

    private function decimalIsValid(string $value, int $scale, int $integerDigits): bool
    {
        return preg_match(
            '/\A(?:0|[1-9][0-9]{0,'.($integerDigits - 1).'})(?:\.[0-9]{1,'.$scale.'})?\z/',
            $value,
        ) === 1;
    }

    private function vatIsValid(string $value): bool
    {
        if (preg_match('/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?\z/', $value) !== 1) {
            return false;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole < 100 || ((int) $whole === 100 && trim($fraction, '0') === '');
    }

    private function dateIsValid(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
