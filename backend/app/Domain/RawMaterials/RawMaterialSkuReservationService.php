<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Sku\SkuAllocationService;
use App\Domain\Sku\SkuPrefix;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RawMaterialSkuReservationService
{
    public function __construct(
        private RawMaterialAuthorization $authorization,
        private AuthorizationMutationContext $contexts,
        private SkuAllocationService $allocation,
    ) {}

    public function reserve(User $actor, Location $context, string $key): array
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,120}$/D', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => [__('messages.raw_material_idempotency_key_required')]]);
        }
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $key): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);
            $prior = DB::table('raw_material_sku_reservations')
                ->where('organization_id', $actor->organization_id)->where('actor_user_id', $actor->id)
                ->where('idempotency_key', $key)->first();
            if ($prior) {
                if ($prior->location_id !== $context->id) {
                    throw new RawMaterialException('RAW_SKU_IDEMPOTENCY_CONFLICT', 'Klucz rezerwacji należy do innego kontekstu.', 409);
                }

                return $this->present($prior);
            }
            $recent = DB::table('raw_material_sku_reservations')->where('organization_id', $actor->organization_id)
                ->where('created_at', '>=', now()->subDay());
            if ((clone $recent)->count() >= 1000 || (clone $recent)->where('actor_user_id', $actor->id)->count() >= 100) {
                throw new RawMaterialException('RAW_SKU_ALLOCATION_RATE_LIMIT', 'Przekroczono dzienny limit rezerwacji SKU. Spróbuj później.', 429);
            }
            $id = Str::uuid()->toString();
            try {
                $sku = $this->allocation->allocateNext($actor, SkuPrefix::Raw, $id);
            } catch (\InvalidArgumentException) {
                throw new RawMaterialException('RAW_MATERIAL_CODE_CAPACITY_REACHED', __('messages.raw_material_code_capacity_reached'), 409);
            }
            DB::table('raw_material_sku_reservations')->insert([
                'id' => $id,
                'organization_id' => $actor->organization_id,
                'actor_user_id' => $actor->id,
                'location_id' => $context->id,
                'sku' => $sku,
                'idempotency_key' => $key,
                'created_at' => now(),
                'expires_at' => now()->addDay(),
            ]);
            DB::table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(), 'organization_id' => $actor->organization_id,
                'operation_id' => $id, 'actor_user_id' => $actor->id, 'actor_location_id' => $context->id,
                'subject_type' => 'RAW_MATERIAL', 'subject_id' => $id, 'event_code' => 'SKU_RESERVED',
                'outcome' => 'ACCEPTED', 'after_hash' => hash('sha256', $sku),
                'metadata' => json_encode(['sku' => $sku], JSON_THROW_ON_ERROR),
            ]);

            return $this->present(DB::table('raw_material_sku_reservations')->where('id', $id)->first());
        }));
    }

    public function claim(User $actor, Location $context, string $id, array $input): object
    {
        $reservation = DB::table('raw_material_sku_reservations')->where('id', $id)
            ->where('organization_id', $actor->organization_id)->where('actor_user_id', $actor->id)
            ->where('location_id', $context->id)->lockForUpdate()->first();
        if (! $reservation) {
            throw new RawMaterialException('RAW_SKU_RESERVATION_NOT_FOUND', 'Nie znaleziono rezerwacji SKU w bieżącym kontekście.', 404);
        }
        if ($reservation->consumed_at !== null) {
            if (! hash_equals($reservation->request_hash, $this->requestHash($input))) {
                throw new RawMaterialException('RAW_SKU_RESERVATION_CONFLICT', 'Rezerwację wykorzystano już dla innego zapisu.', 409);
            }
        } elseif (now()->greaterThanOrEqualTo($reservation->expires_at)) {
            throw new RawMaterialException('RAW_SKU_RESERVATION_EXPIRED', 'Rezerwacja SKU wygasła. Otwórz nowy formularz; poprzedni numer nie będzie ponownie użyty.', 409);
        }

        return $reservation;
    }

    public function consume(string $id, array $input, array $response): void
    {
        DB::table('raw_material_sku_reservations')->where('id', $id)->update([
            'consumed_at' => now(), 'request_hash' => $this->requestHash($input),
            'response_body' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function requestHash(array $input): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function present(object $row): array
    {
        return [
            'reservation_id' => $row->id, 'sku' => $row->sku, 'expires_at' => $row->expires_at,
            'status' => $row->consumed_at !== null ? 'CONSUMED' : (now()->greaterThanOrEqualTo($row->expires_at) ? 'EXPIRED' : 'RESERVED'),
        ];
    }
}
