<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\Permission;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RawMaterialCatalogValues
{
    public function __construct(private RawMaterialAuthorization $authorization) {}

    public function canReadPrice(User $actor): bool
    {
        return $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::ReferencePriceView);
    }

    public function canReadCost(User $actor, Location $context): bool
    {
        return $this->canReadPrice($actor)
            && app(BusinessAuthorization::class)->allows($actor, $context, $context, Permission::FoodcostView);
    }

    public static function quantities(array $input): array
    {
        $nominal = $input['nominal_quantity'] ?? null;
        $usable = $input['usable_quantity'] ?? $nominal;
        if ($nominal === null && $usable === null) {
            return ['nominal_quantity' => null, 'usable_quantity' => null];
        }
        foreach (['nominal_quantity' => $nominal, 'usable_quantity' => $usable] as $field => $value) {
            if (! is_string($value) || ! preg_match('/^\d{1,18}(?:\.\d{1,6})?$/D', $value)
                || ! preg_match('/[1-9]/', $value)
                || ($input['base_unit_code'] === 'pc' && preg_match('/\.[0-9]*[1-9]/', $value))) {
                throw ValidationException::withMessages([$field => ['Ilość musi być dodatnią liczbą z maksymalnie sześcioma miejscami po przecinku; sztuki muszą być całkowite.']]);
            }
        }
        $values = DB::selectOne('SELECT ?::numeric(24,6)::text AS nominal, ?::numeric(24,6)::text AS usable', [$nominal, $usable]);

        return ['nominal_quantity' => $values->nominal, 'usable_quantity' => $values->usable];
    }

    public static function packageExpression(): string
    {
        return '(SELECT coalesce(prices.net_pln_per_package, prices.net_pln_per_base_unit * revisions.nominal_quantity)
            FROM raw_material_reference_price_events prices
            WHERE prices.organization_id = materials.organization_id AND prices.raw_material_id = materials.id
                AND prices.effective_from <= CURRENT_DATE
            ORDER BY prices.effective_from DESC, prices.event_no DESC LIMIT 1)';
    }

    public static function costExpression(): string
    {
        return '('.self::packageExpression().' / NULLIF(revisions.usable_quantity, 0) * CASE revisions.base_unit_code
            WHEN \'mg\' THEN 100000 WHEN \'g\' THEN 100 WHEN \'kg\' THEN 0.1
            WHEN \'ml\' THEN 100 WHEN \'l\' THEN 0.1 WHEN \'pc\' THEN 1 END)';
    }

    public function select(Builder $query, User $actor, Location $context): void
    {
        $query->addSelect('revisions.nominal_quantity', 'revisions.usable_quantity')
            ->selectRaw('raw_materials_round_half_even_v1(revisions.usable_quantity * 100 / NULLIF(revisions.nominal_quantity, 0), 6)::text AS yield_percent');
        if ($this->canReadPrice($actor)) {
            $query->selectRaw('raw_materials_round_half_even_v1('.self::packageExpression().', 14)::text AS reference_net_price');
        }
        if ($this->canReadCost($actor, $context)) {
            $query->selectRaw('raw_materials_round_half_even_v1('.self::costExpression().', 14)::text AS usable_unit_cost');
        }
    }

    public static function present(object $row): array
    {
        $result = [
            'nominal_quantity' => $row->nominal_quantity,
            'usable_quantity' => $row->usable_quantity,
            'yield_percent' => $row->yield_percent,
        ];
        if (property_exists($row, 'reference_net_price')) {
            $result['reference_net_price'] = $row->reference_net_price;
            $result['currency_code'] = 'PLN';
        }
        if (property_exists($row, 'usable_unit_cost')) {
            $result['usable_unit_cost'] = $row->usable_unit_cost;
            $result['usable_cost_basis'] = match ($row->base_unit_code) {
                'mg', 'g', 'kg' => 'PLN/100g',
                'ml', 'l' => 'PLN/100ml',
                'pc' => 'PLN/pc',
            };
        }

        return $result;
    }
}
