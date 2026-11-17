<?php

namespace App\Domain\RawMaterials;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RawMaterialUnitRegistry
{
    /**
     * @return array{base_unit_code: string, base_unit_dimension: string, units: list<array<string, string|null>>}
     */
    public static function resolve(string $baseUnit, array $conversions, array $allowedArchivedCodes = []): array
    {
        $codes = array_values(array_unique([
            $baseUnit,
            ...array_map(static fn (array $conversion): string => (string) ($conversion['unit_code'] ?? ''), $conversions),
        ]));
        $definitions = DB::table('raw_material_units')->whereIn('code', $codes)->get()->keyBy('code');
        $base = $definitions->get($baseUnit);
        if (! $base || ! self::assignable($base, $allowedArchivedCodes)) {
            throw ValidationException::withMessages([
                'base_unit_code' => [__('messages.raw_material_unit_invalid')],
            ]);
        }

        $units = [[
            'unit_code' => $baseUnit,
            'dimension' => $base->dimension,
            'conversion_kind' => 'BASE',
            'to_base_factor' => '1.000000000000',
            'basis' => null,
        ]];
        $seen = [$baseUnit => true];

        foreach ($conversions as $index => $conversion) {
            $code = (string) ($conversion['unit_code'] ?? '');
            $unit = $definitions->get($code);
            if (! $unit || ! self::assignable($unit, $allowedArchivedCodes) || isset($seen[$code])) {
                throw ValidationException::withMessages([
                    "conversions.{$index}.unit_code" => [__('messages.raw_material_unit_invalid')],
                ]);
            }
            $seen[$code] = true;

            if ($unit->dimension === $base->dimension) {
                $factor = self::factorToBase((string) $unit->to_base_factor, (string) $base->to_base_factor);
                if (isset($conversion['to_base_factor'])
                    && self::decimal((string) $conversion['to_base_factor']) !== $factor) {
                    throw ValidationException::withMessages([
                        "conversions.{$index}.to_base_factor" => [__('messages.raw_material_metric_factor_invalid')],
                    ]);
                }
                $units[] = [
                    'unit_code' => $code,
                    'dimension' => $unit->dimension,
                    'conversion_kind' => 'METRIC',
                    'to_base_factor' => $factor,
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
                'dimension' => $unit->dimension,
                'conversion_kind' => 'MATERIAL_SPECIFIC',
                'to_base_factor' => self::decimal((string) $conversion['to_base_factor']),
                'basis' => trim((string) $conversion['basis']),
            ];
        }

        return [
            'base_unit_code' => $baseUnit,
            'base_unit_dimension' => $base->dimension,
            'units' => $units,
        ];
    }

    public static function factorToBase(string $unitFactor, string $baseFactor): string
    {
        $result = DB::selectOne(
            'SELECT (CAST(? AS numeric(24,12)) / CAST(? AS numeric(24,12)))::numeric(24,12)::text AS factor',
            [$unitFactor, $baseFactor],
        );

        return self::decimal((string) $result->factor);
    }

    public static function dimension(string $code): ?string
    {
        return DB::table('raw_material_units')->where('code', $code)->value('dimension');
    }

    public static function isActive(string $code): bool
    {
        return DB::table('raw_material_units')->where('code', $code)->whereNull('archived_at')->exists();
    }

    private static function assignable(object $unit, array $allowedArchivedCodes): bool
    {
        return $unit->archived_at === null || in_array($unit->code, $allowedArchivedCodes, true);
    }

    private static function decimal(string $value): string
    {
        if (! preg_match('/^\d{1,12}(?:\.\d{1,12})?$/D', $value) || ! preg_match('/[1-9]/', $value)) {
            throw ValidationException::withMessages([
                'conversions' => [__('messages.raw_material_conversion_factor_invalid')],
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ltrim($whole, '0') === ''
            ? '0.'.str_pad($fraction, 12, '0')
            : ltrim($whole, '0').'.'.str_pad($fraction, 12, '0');
    }
}
