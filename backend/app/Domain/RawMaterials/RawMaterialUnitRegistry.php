<?php

namespace App\Domain\RawMaterials;

use Illuminate\Validation\ValidationException;

final class RawMaterialUnitRegistry
{
    private const UNITS = [
        'mg' => ['dimension' => 'MASS', 'scale' => -3],
        'g' => ['dimension' => 'MASS', 'scale' => 0],
        'kg' => ['dimension' => 'MASS', 'scale' => 3],
        'ml' => ['dimension' => 'VOLUME', 'scale' => 0],
        'l' => ['dimension' => 'VOLUME', 'scale' => 3],
        'pc' => ['dimension' => 'COUNT', 'scale' => 0],
    ];

    /**
     * @return array{base_unit_code: string, base_unit_dimension: string, units: list<array<string, string|null>>}
     */
    public static function resolve(string $baseUnit, array $conversions): array
    {
        $base = self::UNITS[$baseUnit] ?? null;
        if ($base === null) {
            throw ValidationException::withMessages([
                'base_unit_code' => [__('messages.raw_material_unit_invalid')],
            ]);
        }

        $units = [[
            'unit_code' => $baseUnit,
            'dimension' => $base['dimension'],
            'conversion_kind' => 'BASE',
            'to_base_factor' => '1.000000000000',
            'basis' => null,
        ]];
        $seen = [$baseUnit => true];

        foreach ($conversions as $index => $conversion) {
            $code = $conversion['unit_code'];
            $unit = self::UNITS[$code] ?? null;
            if ($unit === null || isset($seen[$code])) {
                throw ValidationException::withMessages([
                    "conversions.{$index}.unit_code" => [__('messages.raw_material_unit_invalid')],
                ]);
            }
            $seen[$code] = true;

            if ($unit['dimension'] === $base['dimension']) {
                $factor = self::metricFactor($unit['scale'] - $base['scale']);
                if (isset($conversion['to_base_factor'])
                    && self::decimal((string) $conversion['to_base_factor']) !== self::decimal($factor)) {
                    throw ValidationException::withMessages([
                        "conversions.{$index}.to_base_factor" => [__('messages.raw_material_metric_factor_invalid')],
                    ]);
                }
                $units[] = [
                    'unit_code' => $code,
                    'dimension' => $unit['dimension'],
                    'conversion_kind' => 'METRIC',
                    'to_base_factor' => self::decimal($factor),
                    'basis' => null,
                ];

                continue;
            }

            if (! isset($conversion['to_base_factor']) || ! isset($conversion['basis'])) {
                throw ValidationException::withMessages([
                    "conversions.{$index}.basis" => [__('messages.raw_material_conversion_basis_required')],
                ]);
            }

            $units[] = [
                'unit_code' => $code,
                'dimension' => $unit['dimension'],
                'conversion_kind' => 'MATERIAL_SPECIFIC',
                'to_base_factor' => self::decimal((string) $conversion['to_base_factor']),
                'basis' => trim((string) $conversion['basis']),
            ];
        }

        return [
            'base_unit_code' => $baseUnit,
            'base_unit_dimension' => $base['dimension'],
            'units' => $units,
        ];
    }

    private static function decimal(string $value): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,12})?$/D', $value) || ! preg_match('/[1-9]/', $value)) {
            throw ValidationException::withMessages([
                'conversions' => [__('messages.raw_material_conversion_factor_invalid')],
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ltrim($whole, '0') === '' ? '0.'.str_pad($fraction, 12, '0') : ltrim($whole, '0').'.'.str_pad($fraction, 12, '0');
    }

    private static function metricFactor(int $power): string
    {
        if ($power >= 0) {
            return '1'.str_repeat('0', $power);
        }

        return '0.'.str_repeat('0', abs($power) - 1).'1';
    }
}
