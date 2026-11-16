<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RawMaterialPricingService
{
    public function __construct(
        private readonly RawMaterialAuthorization $authorization,
        private readonly AuthorizationMutationContext $contexts,
    ) {}

    public function prepareRevisionInput(
        User $actor,
        Location $context,
        array $input,
        array $unitData,
        ?object $baseRevision,
    ): array {
        $quantities = RawMaterialCatalogValues::quantities($input);
        $supplied = array_key_exists('pricing', $input);
        $pricing = $input['pricing'] ?? null;
        unset($input['pricing']);

        if ($supplied) {
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceManage);
            if ($quantities['nominal_quantity'] === null) {
                throw ValidationException::withMessages([
                    'nominal_quantity' => ['Cena wymaga podania ilości nominalnej.'],
                ]);
            }
            $input += $this->revisionPricingFields(
                $pricing['basis'],
                $pricing['amount'],
                $pricing['unit_code'] ?? null,
            );
            $this->assertInputUnit($input['pricing_input_basis'], $input['pricing_input_unit_code'], $unitData);
            $this->validateRevisionPrice(
                $input['pricing_input_basis'],
                $input['pricing_input_amount'],
                $input['pricing_input_unit_code'],
                $quantities['nominal_quantity'],
                $unitData,
            );

            return $input;
        }

        if ($baseRevision === null) {
            return $input;
        }

        $quantityChanged = $this->quantityChanged($baseRevision, $quantities);
        $pendingPricing = $baseRevision->pricing_input_basis !== null
            && ! DB::table('raw_material_reference_price_events')
                ->where('pricing_revision_id', $baseRevision->id)
                ->exists();
        if ($pendingPricing) {
            if ($quantityChanged) {
                $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceManage);
            }
            $fields = $this->revisionPricingFields(
                $baseRevision->pricing_input_basis,
                (string) $baseRevision->pricing_input_amount,
                $baseRevision->pricing_input_unit_code,
            );
            $this->assertInputUnit($baseRevision->pricing_input_basis, $baseRevision->pricing_input_unit_code, $unitData);
            $this->validateRevisionPrice(
                $fields['pricing_input_basis'],
                $fields['pricing_input_amount'],
                $fields['pricing_input_unit_code'],
                $quantities['nominal_quantity'],
                $unitData,
            );

            return $input + $fields;
        }

        if (! $quantityChanged) {
            return $input;
        }

        $latest = DB::table('raw_material_reference_price_events')
            ->where('organization_id', $actor->organization_id)
            ->where('raw_material_id', $baseRevision->raw_material_id)
            ->where('effective_from', '<=', now()->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('event_no')->first();
        if (! $latest || $latest->event_type !== 'PRICE_SET') {
            return $input;
        }

        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceManage);
        $basis = $latest->pricing_input_basis;
        $amount = $latest->pricing_input_amount;
        $unitCode = $latest->pricing_input_unit_code;
        if ($basis === null && $latest->net_pln_per_package !== null) {
            $basis = 'PACKAGE';
            $amount = $latest->net_pln_per_package;
            $unitCode = null;
        } elseif ($basis === null) {
            $basis = 'LEGACY_BASE_UNIT';
            $amount = $latest->net_pln_per_base_unit;
            $unitCode = $baseRevision->base_unit_code;
        }

        $fields = $this->revisionPricingFields($basis, (string) $amount, $unitCode);
        $this->assertInputUnit($basis, $unitCode, $unitData);
        $this->validateRevisionPrice(
            $fields['pricing_input_basis'],
            $fields['pricing_input_amount'],
            $fields['pricing_input_unit_code'],
            $quantities['nominal_quantity'],
            $unitData,
        );

        return $input + $fields;
    }

    public function recordApprovedRevision(
        User $actor,
        Location $context,
        object $material,
        object $revision,
        string $operationId,
    ): ?array {
        if ($revision->pricing_input_basis === null) {
            return null;
        }
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceManage);
        $quantities = RawMaterialCatalogValues::quantities([
            'base_unit_code' => $revision->base_unit_code,
            'nominal_quantity' => (string) $revision->nominal_quantity,
            'usable_quantity' => (string) $revision->usable_quantity,
        ]);
        $calculated = $this->calculate(
            $revision->pricing_input_basis,
            (string) $revision->pricing_input_amount,
            $revision->pricing_input_unit_code,
            $quantities['nominal_quantity'],
            $revision->base_unit_code,
            $revision->id,
            $actor->organization_id,
        );
        $eventNo = (int) DB::table('raw_material_reference_price_events')
            ->where('organization_id', $actor->organization_id)
            ->where('raw_material_id', $material->id)->max('event_no') + 1;
        $eventId = Str::uuid()->toString();
        $event = [
            'id' => $eventId,
            'organization_id' => $actor->organization_id,
            'raw_material_id' => $material->id,
            'event_no' => $eventNo,
            'event_type' => 'PRICE_SET',
            'effective_from' => now()->toDateString(),
            'net_pln_per_base_unit' => $calculated['net_pln_per_base_unit'],
            'net_pln_per_package' => $calculated['net_pln_per_package'],
            'pricing_input_basis' => $revision->pricing_input_basis,
            'pricing_input_amount' => $revision->pricing_input_amount,
            'pricing_input_unit_code' => $revision->pricing_input_unit_code,
            'pricing_nominal_quantity' => $quantities['nominal_quantity'],
            'pricing_usable_quantity' => $quantities['usable_quantity'],
            'pricing_revision_id' => $revision->id,
            'reason_code' => 'RAW_MATERIAL_APPROVAL',
            'recorded_by' => $actor->id,
            'operation_id' => $operationId,
        ];

        $this->contexts->run(
            AuthorizationMutationScope::rawMaterialCommercial(),
            $actor->id,
            fn () => DB::table('raw_material_reference_price_events')->insert($event),
        );

        return ['id' => $eventId, 'event_no' => $eventNo];
    }

    public function normalizeCsvPrice(
        string $amount,
        string $unitCode,
        string $nominalQuantity,
        string $usableQuantity,
        string $baseUnitCode,
        string $revisionId,
        string $organizationId,
        string $unitFactor,
    ): array {
        $factor = $this->decimalResult(
            'SELECT CAST(? AS numeric)::text AS factor',
            [$unitFactor],
        );
        $calculated = $this->calculateWithFactor(
            'NOMINAL_UNIT',
            $amount,
            $unitCode,
            $nominalQuantity,
            $baseUnitCode,
            $factor,
        );

        return [
            ...$calculated,
            'pricing_input_basis' => 'NOMINAL_UNIT',
            'pricing_input_amount' => $amount,
            'pricing_input_unit_code' => $unitCode,
            'pricing_nominal_quantity' => $nominalQuantity,
            'pricing_usable_quantity' => $usableQuantity,
            'pricing_revision_id' => $revisionId,
        ];
    }

    public function normalizeReferencePrice(string $basis, string $amount, string $nominalQuantity): array
    {
        return $this->calculateWithFactor(
            $basis,
            $amount,
            null,
            $nominalQuantity,
            '',
            $basis === 'PACKAGE' ? null : '1',
        );
    }

    public function preview(User $actor, Location $context, array $input): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceView);
        $quantities = RawMaterialCatalogValues::quantities($input);
        $unitCode = $input['pricing']['unit_code'] ?? null;
        $conversions = $unitCode !== null && $unitCode !== $input['base_unit_code']
            ? [['unit_code' => $unitCode]] : [];
        $units = RawMaterialUnitRegistry::resolve($input['base_unit_code'], $conversions);
        $prepared = $this->prepareRevisionInput($actor, $context, $input, $units, null);
        $unit = collect($units['units'])->firstWhere('unit_code', $unitCode);
        $values = $this->calculateWithFactor(
            $prepared['pricing_input_basis'],
            $prepared['pricing_input_amount'],
            $prepared['pricing_input_unit_code'],
            $quantities['nominal_quantity'],
            $input['base_unit_code'],
            $unit['to_base_factor'] ?? null,
        );
        $result = ['net_pln_per_package' => $values['net_pln_per_package']];
        if (app(RawMaterialCatalogValues::class)->canReadCost($actor, $context)) {
            $expression = RawMaterialCatalogValues::usableCostExpression(
                'CAST(? AS numeric)', 'CAST(? AS numeric)', 'CAST(? AS text)',
            );
            $result['usable_unit_cost'] = (string) DB::scalar(
                'SELECT raw_materials_round_half_even_v1('.$expression.', 14)::text',
                [$values['net_pln_per_package'], $quantities['usable_quantity'], $input['base_unit_code']],
            );
            $result['usable_cost_basis'] = match ($input['base_unit_code']) {
                'mg', 'g', 'kg' => 'PLN/100g',
                'ml', 'l' => 'PLN/100ml',
                'pc' => 'PLN/pc',
            };
        }

        return $result;
    }

    private function revisionPricingFields(string $basis, string $amount, ?string $unitCode): array
    {
        if (! in_array($basis, ['PACKAGE', 'NOMINAL_UNIT', 'LEGACY_BASE_UNIT'], true)) {
            throw ValidationException::withMessages(['pricing' => ['Nieprawidłowa podstawa ceny.']]);
        }
        if (! preg_match('/^\d{1,17}(?:\.\d{1,14})?$/D', $amount)) {
            throw ValidationException::withMessages(['pricing.amount' => ['Podaj cenę z maksymalnie 14 miejscami po przecinku.']]);
        }
        $amount = $this->decimalResult(
            'SELECT CAST(? AS numeric(31,14))::text AS amount',
            [$amount],
        );
        if (($basis === 'PACKAGE' && $unitCode !== null)
            || ($basis === 'NOMINAL_UNIT' && ! in_array($unitCode, ['kg', 'l', 'pc'], true))
            || ($basis === 'LEGACY_BASE_UNIT' && ! in_array($unitCode, ['mg', 'g', 'kg', 'ml', 'l', 'pc'], true))) {
            throw ValidationException::withMessages(['pricing.unit_code' => ['Jednostka ceny nie odpowiada wybranej podstawie.']]);
        }

        return [
            'pricing_input_basis' => $basis,
            'pricing_input_amount' => $amount,
            'pricing_input_unit_code' => $unitCode,
        ];
    }

    private function calculate(
        string $basis,
        string $amount,
        ?string $unitCode,
        string $nominalQuantity,
        string $baseUnitCode,
        string $revisionId,
        string $organizationId,
    ): array {
        $unitFactor = null;
        if ($basis === 'LEGACY_BASE_UNIT') {
            if ($unitCode !== $baseUnitCode) {
                throw ValidationException::withMessages([
                    'pricing.unit_code' => ['Nie można zachować historycznej ceny po zmianie jednostki podstawowej. Podaj nową cenę.'],
                ]);
            }
            $unitFactor = '1';
        } elseif ($basis !== 'PACKAGE') {
            $unit = DB::table('raw_material_revision_units')
                ->where('organization_id', $organizationId)
                ->where('revision_id', $revisionId)
                ->where('unit_code', $unitCode)->first();
            if (! $unit || ! in_array($unit->conversion_kind, ['BASE', 'METRIC'], true)) {
                throw ValidationException::withMessages([
                    'pricing.unit_code' => ['Wybrana jednostka ceny nie jest zatwierdzoną jednostką tego surowca.'],
                ]);
            }
            $expectedDimension = match ($unitCode) {
                'kg' => 'MASS',
                'l' => 'VOLUME',
                'pc' => 'COUNT',
                default => null,
            };
            if ($unit->dimension !== $expectedDimension) {
                throw ValidationException::withMessages([
                    'pricing.unit_code' => ['Jednostka ceny musi odpowiadać wymiarowi surowca.'],
                ]);
            }
            $unitFactor = (string) $unit->to_base_factor;
        }

        return $this->calculateWithFactor($basis, $amount, $unitCode, $nominalQuantity, $baseUnitCode, $unitFactor);
    }

    private function assertInputUnit(string $basis, ?string $unitCode, array $unitData): void
    {
        if ($basis === 'PACKAGE') {
            return;
        }
        $unit = collect($unitData['units'])->firstWhere('unit_code', $unitCode);
        $expectedDimension = match ($unitCode) {
            'mg', 'g', 'kg' => 'MASS',
            'ml', 'l' => 'VOLUME',
            'pc' => 'COUNT',
            default => null,
        };
        if (! $unit || $unit['dimension'] !== $expectedDimension
            || ! in_array($unit['conversion_kind'], ['BASE', 'METRIC'], true)
            || ($basis === 'LEGACY_BASE_UNIT' && $unitCode !== $unitData['base_unit_code'])
            || ($basis === 'NOMINAL_UNIT' && $unitCode !== match ($expectedDimension) {
                'MASS' => 'kg',
                'VOLUME' => 'l',
                'COUNT' => 'pc',
                default => null,
            })) {
            throw ValidationException::withMessages([
                'pricing.unit_code' => ['Wybrana jednostka ceny nie jest zgodna z jednostkami surowca.'],
            ]);
        }
    }

    private function validateRevisionPrice(
        string $basis,
        string $amount,
        ?string $unitCode,
        string $nominalQuantity,
        array $unitData,
    ): void {
        $unitFactor = null;
        if ($basis !== 'PACKAGE') {
            $unit = collect($unitData['units'])->firstWhere('unit_code', $unitCode);
            if (! is_array($unit) || ! isset($unit['to_base_factor'])) {
                throw ValidationException::withMessages([
                    'pricing.unit_code' => ['Nie można przeliczyć jednostki ceny.'],
                ]);
            }
            $unitFactor = (string) $unit['to_base_factor'];
        }
        $this->calculateWithFactor(
            $basis,
            $amount,
            $unitCode,
            $nominalQuantity,
            $unitData['base_unit_code'],
            $unitFactor,
        );
    }

    private function calculateWithFactor(
        string $basis,
        string $amount,
        ?string $unitCode,
        string $nominalQuantity,
        string $baseUnitCode,
        ?string $unitFactor,
    ): array {
        if ($basis === 'PACKAGE') {
            $values = DB::selectOne(<<<'SQL'
                SELECT raw_materials_round_half_even_v1(CAST(? AS numeric) / CAST(? AS numeric), 14)::text AS base_unit_price,
                    raw_materials_round_half_even_v1(CAST(? AS numeric), 14)::text AS package_price
                SQL, [$amount, $nominalQuantity, $amount]);
        } else {
            if ($unitFactor === null) {
                throw ValidationException::withMessages(['pricing.unit_code' => ['Nie można przeliczyć jednostki ceny.']]);
            }
            $values = DB::selectOne(<<<'SQL'
                SELECT raw_materials_round_half_even_v1(CAST(? AS numeric) / CAST(? AS numeric), 14)::text AS base_unit_price,
                    raw_materials_round_half_even_v1(CAST(? AS numeric) / CAST(? AS numeric) * CAST(? AS numeric), 14)::text AS package_price
                SQL, [$amount, $unitFactor, $nominalQuantity, $unitFactor, $amount]);
        }
        if (! DB::scalar(<<<'SQL'
            SELECT CAST(? AS numeric) < 100000000000000000
                AND CAST(? AS numeric) < 100000000000000000
            SQL, [$values->base_unit_price, $values->package_price])) {
            throw ValidationException::withMessages([
                'pricing.amount' => ['Cena po przeliczeniu przekracza dozwolony zakres.'],
            ]);
        }

        return [
            'net_pln_per_base_unit' => $values->base_unit_price,
            'net_pln_per_package' => $values->package_price,
        ];
    }

    private function quantityChanged(object $baseRevision, array $quantities): bool
    {
        return (bool) DB::scalar(<<<'SQL'
            SELECT CAST(? AS numeric) IS DISTINCT FROM CAST(? AS numeric)
                OR CAST(? AS numeric) IS DISTINCT FROM CAST(? AS numeric)
            SQL, [
            $baseRevision->nominal_quantity, $quantities['nominal_quantity'],
            $baseRevision->usable_quantity, $quantities['usable_quantity'],
        ]);
    }

    private function decimalResult(string $query, array $bindings): string
    {
        return (string) DB::scalar($query, $bindings);
    }
}
