<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RawMaterialCommercialService
{
    public function __construct(
        private RawMaterialAuthorization $authorization,
        private AuthorizationMutationContext $contexts,
    ) {}

    public function suppliers(User $actor, Location $context, array $filters = []): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::SupplierView);
        $query = DB::table('organization_suppliers')
            ->where('organization_id', $actor->organization_id)
            ->select(['id', 'supplier_code', 'display_name', 'legal_name', 'lifecycle_state', 'created_at']);
        if (isset($filters['search'])) {
            $query->where('display_name', 'ilike', '%'.addcslashes($filters['search'], '%_\\').'%');
        }
        if (isset($filters['lifecycle_state'])) {
            $query->where('lifecycle_state', $filters['lifecycle_state']);
        }
        $page = $query->orderBy('display_name')->orderBy('id')->paginate($filters['per_page'] ?? 20);

        return [
            'data' => $page->getCollection()->map(fn (object $row): array => (array) $row)->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    public function productRevisions(User $actor, Location $context, string $productId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::SupplierView);
        $product = DB::table('supplier_products')->where('organization_id', $actor->organization_id)
            ->where('id', $productId)->first();
        if (! $product) {
            $this->notFound();
        }

        return ['data' => DB::table('supplier_product_revisions')->where('organization_id', $actor->organization_id)
            ->where('supplier_product_id', $productId)->orderByDesc('revision_no')
            ->get()
            ->map(fn (object $revision): array => [
                'id' => $revision->id,
                'revision_no' => $revision->revision_no,
                'raw_material_revision_id' => $revision->raw_material_revision_id,
                'product_name' => $revision->product_name,
                'supplier_sku' => $revision->supplier_sku,
                'gtin' => $revision->gtin,
                'purchase_unit_code' => $revision->purchase_unit_code,
                'content_quantity' => (string) $revision->content_quantity,
                'content_unit_code' => $revision->content_unit_code,
                'to_base_factor' => (string) $revision->to_base_factor_snapshot,
                'base_quantity' => (string) $revision->base_quantity_snapshot,
                'compatibility_status' => 'PENDING',
                'created_at' => $revision->created_at,
            ])->all()];
    }

    public function createSupplier(
        User $actor,
        Location $context,
        array $input,
        string $idempotencyKey,
    ): array {
        return $this->mutate(
            $actor,
            $context,
            [RawMaterialPermission::SupplierManage],
            'supplier.create',
            $idempotencyKey,
            $input,
            function (string $operationId) use ($actor, $context, $input): array {
                $id = Str::uuid()->toString();
                DB::table('organization_suppliers')->insert([
                    'id' => $id,
                    'organization_id' => $actor->organization_id,
                    'supplier_code' => $input['supplier_code'],
                    'display_name' => trim($input['display_name']),
                    'legal_name' => isset($input['legal_name']) ? trim($input['legal_name']) : null,
                    'tax_identifier_ciphertext' => filled($input['tax_identifier'] ?? null)
                        ? Crypt::encryptString(trim($input['tax_identifier']))
                        : null,
                    'created_by' => $actor->id,
                ]);
                $supplier = $this->supplier($actor, $id);
                $this->audit($actor, $context, $operationId, 'SUPPLIER', $id, 'SUPPLIER_CREATED', null, $supplier);

                return ['data' => $supplier];
            },
        );
    }

    public function archiveSupplier(
        User $actor,
        Location $context,
        string $supplierId,
        string $reason,
        string $idempotencyKey,
    ): array {
        return $this->mutate(
            $actor,
            $context,
            [RawMaterialPermission::SupplierManage],
            'supplier.archive',
            $idempotencyKey,
            ['supplier_id' => $supplierId, 'reason' => $reason],
            function (string $operationId) use ($actor, $context, $supplierId, $reason): array {
                $supplier = DB::table('organization_suppliers')->where('organization_id', $actor->organization_id)
                    ->where('id', $supplierId)->lockForUpdate()->first();
                if (! $supplier) {
                    $this->notFound();
                }
                if ($supplier->lifecycle_state !== 'ACTIVE') {
                    $this->conflict('RAW_MATERIAL_SUPPLIER_STATE_INVALID', 'Dostawca nie jest aktywny.');
                }
                DB::table('organization_suppliers')->where('id', $supplierId)->update([
                    'lifecycle_state' => 'ARCHIVED',
                    'updated_at' => now(),
                ]);
                $this->audit($actor, $context, $operationId, 'SUPPLIER', $supplierId, 'SUPPLIER_ARCHIVED',
                    $supplier, ['lifecycle_state' => 'ARCHIVED'], $reason);

                return ['data' => $this->supplier($actor, $supplierId)];
            },
        );
    }

    public function products(User $actor, Location $context, string $materialId, array $filters = []): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::SupplierView);
        $this->material($actor, $materialId);
        $query = DB::table('supplier_products as products')
            ->join('organization_suppliers as suppliers', function ($join): void {
                $join->on('suppliers.id', '=', 'products.supplier_id')
                    ->on('suppliers.organization_id', '=', 'products.organization_id');
            })
            ->leftJoin('supplier_product_revisions as revisions', function ($join): void {
                $join->on('revisions.supplier_product_id', '=', 'products.id')
                    ->on('revisions.organization_id', '=', 'products.organization_id');
            })
            ->where('products.organization_id', $actor->organization_id)
            ->where('products.raw_material_id', $materialId)
            ->whereRaw('revisions.revision_no = (SELECT max(latest.revision_no)
                FROM supplier_product_revisions latest
                WHERE latest.supplier_product_id = products.id
                    AND latest.organization_id = products.organization_id)')
            ->select([
                'products.id', 'products.lifecycle_state', 'products.created_at',
                'suppliers.id as supplier_id', 'suppliers.supplier_code', 'suppliers.display_name as supplier_name',
                'revisions.id as revision_id', 'revisions.revision_no', 'revisions.product_name',
                'revisions.supplier_sku', 'revisions.gtin', 'revisions.purchase_unit_code',
                'revisions.content_quantity', 'revisions.content_unit_code', 'revisions.base_quantity_snapshot',
            ]);
        if (isset($filters['supplier_id'])) {
            $query->where('products.supplier_id', $filters['supplier_id']);
        }
        if (isset($filters['lifecycle_state'])) {
            $query->where('products.lifecycle_state', $filters['lifecycle_state']);
        }
        $page = $query->orderBy('products.created_at')->orderBy('products.id')->paginate($filters['per_page'] ?? 20);

        return [
            'data' => $page->getCollection()->map(fn (object $row): array => [
                'id' => $row->id,
                'lifecycle_state' => $row->lifecycle_state,
                'supplier' => ['id' => $row->supplier_id, 'code' => $row->supplier_code, 'name' => $row->supplier_name],
                'current_revision' => [
                    'id' => $row->revision_id,
                    'revision_no' => $row->revision_no,
                    'product_name' => $row->product_name,
                    'supplier_sku' => $row->supplier_sku,
                    'gtin' => $row->gtin,
                    'purchase_unit_code' => $row->purchase_unit_code,
                    'content_quantity' => (string) $row->content_quantity,
                    'content_unit_code' => $row->content_unit_code,
                    'base_quantity' => (string) $row->base_quantity_snapshot,
                    'compatibility_status' => 'PENDING',
                ],
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    public function createProduct(
        User $actor,
        Location $context,
        string $materialId,
        array $input,
        string $idempotencyKey,
    ): array {
        return $this->mutate(
            $actor,
            $context,
            [RawMaterialPermission::SupplierManage],
            'supplier-product.create',
            $idempotencyKey,
            ['material_id' => $materialId] + $input,
            function (string $operationId) use ($actor, $context, $materialId, $input): array {
                $material = $this->material($actor, $materialId, activeOnly: true);
                $supplier = DB::table('organization_suppliers')->where('organization_id', $actor->organization_id)
                    ->where('id', $input['supplier_id'])->where('lifecycle_state', 'ACTIVE')->lockForUpdate()->first();
                if (! $supplier) {
                    $this->notFound();
                }
                $id = Str::uuid()->toString();
                DB::table('supplier_products')->insert([
                    'id' => $id,
                    'organization_id' => $actor->organization_id,
                    'supplier_id' => $supplier->id,
                    'raw_material_id' => $material->id,
                    'created_by' => $actor->id,
                ]);
                $revision = $this->insertProductRevision($actor, $id, $material, $input, 1);
                $this->audit($actor, $context, $operationId, 'SUPPLIER_PRODUCT', $id,
                    'SUPPLIER_PRODUCT_REVISION_CREATED', null, ['revision_id' => $revision->id]);

                return ['data' => $this->presentProduct($actor, $id)];
            },
        );
    }

    public function reviseProduct(
        User $actor,
        Location $context,
        string $productId,
        array $input,
        string $idempotencyKey,
    ): array {
        return $this->mutate(
            $actor,
            $context,
            [RawMaterialPermission::SupplierManage],
            'supplier-product.revise',
            $idempotencyKey,
            ['product_id' => $productId] + $input,
            function (string $operationId) use ($actor, $context, $productId, $input): array {
                $product = DB::table('supplier_products')->where('organization_id', $actor->organization_id)
                    ->where('id', $productId)->lockForUpdate()->first();
                if (! $product || $product->lifecycle_state !== 'ACTIVE') {
                    $this->notFound();
                }
                $latest = DB::table('supplier_product_revisions')->where('organization_id', $actor->organization_id)
                    ->where('supplier_product_id', $productId)->orderByDesc('revision_no')->first();
                if (! $latest || $latest->id !== $input['expected_revision_id']) {
                    $this->conflict('RAW_MATERIAL_VERSION_CONFLICT', 'Dane produktu zmieniły się. Odśwież widok i spróbuj ponownie.');
                }
                if ($product->supplier_id !== $input['supplier_id']) {
                    $this->notFound();
                }
                $supplier = DB::table('organization_suppliers')
                    ->where('organization_id', $actor->organization_id)
                    ->where('id', $product->supplier_id)->first();
                if (! $supplier || $supplier->lifecycle_state !== 'ACTIVE') {
                    $this->notFound();
                }
                $material = $this->material($actor, $product->raw_material_id, activeOnly: true);
                $revision = $this->insertProductRevision($actor, $productId, $material, $input, $latest->revision_no + 1);
                $this->audit($actor, $context, $operationId, 'SUPPLIER_PRODUCT', $productId,
                    'SUPPLIER_PRODUCT_REVISION_CREATED', ['revision_id' => $latest->id],
                    ['revision_id' => $revision->id]);

                return ['data' => $this->presentProduct($actor, $productId)];
            },
        );
    }

    public function archiveProduct(
        User $actor,
        Location $context,
        string $productId,
        string $reason,
        string $idempotencyKey,
    ): array {
        return $this->mutate(
            $actor,
            $context,
            [RawMaterialPermission::SupplierManage],
            'supplier-product.archive',
            $idempotencyKey,
            ['product_id' => $productId, 'reason' => $reason],
            function (string $operationId) use ($actor, $context, $productId, $reason): array {
                $product = DB::table('supplier_products')->where('organization_id', $actor->organization_id)
                    ->where('id', $productId)->lockForUpdate()->first();
                if (! $product) {
                    $this->notFound();
                }
                if ($product->lifecycle_state !== 'ACTIVE') {
                    $this->conflict('RAW_MATERIAL_SUPPLIER_PRODUCT_STATE_INVALID', 'Produkt dostawcy nie jest aktywny.');
                }
                DB::table('supplier_products')->where('id', $productId)->update([
                    'lifecycle_state' => 'ARCHIVED',
                    'archived_at' => now(),
                    'archived_by' => $actor->id,
                ]);
                $this->audit($actor, $context, $operationId, 'SUPPLIER_PRODUCT', $productId,
                    'SUPPLIER_PRODUCT_ARCHIVED', $product, ['lifecycle_state' => 'ARCHIVED'], $reason);

                return ['data' => ['id' => $productId, 'lifecycle_state' => 'ARCHIVED']];
            },
        );
    }

    public function offers(User $actor, Location $context, string $productId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::SupplierView);
        $product = DB::table('supplier_products')->where('organization_id', $actor->organization_id)
            ->where('id', $productId)->first();
        if (! $product) {
            $this->notFound();
        }

        return ['data' => DB::table('supplier_offers')->where('organization_id', $actor->organization_id)
            ->where('supplier_product_id', $productId)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'offer_reference', 'lifecycle_state', 'created_at'])
            ->map(fn (object $row): array => (array) $row)->all()];
    }

    public function createOffer(
        User $actor,
        Location $context,
        string $productId,
        array $input,
        string $idempotencyKey,
    ): array {
        return $this->mutate(
            $actor,
            $context,
            [RawMaterialPermission::SupplierManage, RawMaterialPermission::PurchasingCostsManage],
            'supplier-offer.create',
            $idempotencyKey,
            ['product_id' => $productId] + $input,
            function (string $operationId) use ($actor, $context, $productId, $input): array {
                $product = DB::table('supplier_products as products')
                    ->join('organization_suppliers as suppliers', function ($join): void {
                        $join->on('suppliers.id', '=', 'products.supplier_id')
                            ->on('suppliers.organization_id', '=', 'products.organization_id');
                    })
                    ->join('raw_materials as materials', function ($join): void {
                        $join->on('materials.id', '=', 'products.raw_material_id')
                            ->on('materials.organization_id', '=', 'products.organization_id');
                    })
                    ->where('products.organization_id', $actor->organization_id)
                    ->where('products.id', $productId)->lockForUpdate()->first([
                        'products.id', 'products.lifecycle_state', 'suppliers.lifecycle_state as supplier_state',
                        'materials.lifecycle_state as material_state', 'materials.approved_revision_id',
                    ]);
                $latestRevision = $product
                    ? DB::table('supplier_product_revisions')->where('organization_id', $actor->organization_id)
                        ->where('supplier_product_id', $productId)->orderByDesc('revision_no')->first()
                    : null;
                if (! $product || $product->lifecycle_state !== 'ACTIVE' || $product->supplier_state !== 'ACTIVE'
                    || $product->material_state !== 'ACTIVE' || $product->approved_revision_id === null
                    || ! $latestRevision || $latestRevision->raw_material_revision_id !== $product->approved_revision_id) {
                    $this->notFound();
                }
                $id = Str::uuid()->toString();
                DB::table('supplier_offers')->insert([
                    'id' => $id,
                    'organization_id' => $actor->organization_id,
                    'supplier_product_id' => $productId,
                    'offer_reference' => filled($input['offer_reference'] ?? null) ? trim($input['offer_reference']) : null,
                    'created_by' => $actor->id,
                ]);
                $this->audit($actor, $context, $operationId, 'SUPPLIER_OFFER', $id, 'SUPPLIER_OFFER_CREATED',
                    null, ['product_id' => $productId]);

                return ['data' => ['id' => $id, 'supplier_product_id' => $productId, 'lifecycle_state' => 'ACTIVE']];
            },
        );
    }

    public function offerPriceEvents(User $actor, Location $context, string $offerId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::PurchasingCostsView);
        $offer = DB::table('supplier_offers')->where('organization_id', $actor->organization_id)
            ->where('id', $offerId)->first();
        if (! $offer) {
            $this->notFound();
        }

        $events = DB::table('supplier_offer_events')->where('organization_id', $actor->organization_id)
            ->where('offer_id', $offerId)->orderBy('event_no')->get();

        return ['data' => $events->map(function (object $event): array {
            $normalization = DB::table('supplier_offer_fx_normalization_events')
                ->where('organization_id', $event->organization_id)
                ->where('supplier_offer_event_id', $event->id)->orderByDesc('recorded_at')->first();

            return [
                'id' => $event->id,
                'event_no' => $event->event_no,
                'event_type' => $event->event_type,
                'valid_from' => $event->valid_from,
                'valid_until' => $event->valid_until,
                'net_amount_per_purchase_unit' => $event->net_amount_per_purchase_unit === null
                    ? null : (string) $event->net_amount_per_purchase_unit,
                'currency_code' => $event->currency_code,
                'minimum_order_quantity' => $event->minimum_order_quantity === null
                    ? null : (string) $event->minimum_order_quantity,
                'quantity_from' => $event->quantity_from === null ? null : (string) $event->quantity_from,
                'quantity_until' => $event->quantity_until === null ? null : (string) $event->quantity_until,
                'normalization_status' => $event->event_type === 'WITHDRAWN'
                    ? 'NOT_APPLICABLE' : ($normalization ? 'NORMALIZED' : 'SOURCE_ONLY'),
                'reporting_amount_pln_per_base_unit' => $normalization
                    ? (string) $normalization->reporting_amount_pln_per_base_unit : null,
                'exchange_rate_event_id' => $normalization?->exchange_rate_event_id,
                'reason_code' => $event->reason_code,
            ];
        })->all()];
    }

    public function recordOfferPriceEvent(
        User $actor,
        Location $context,
        string $offerId,
        array $input,
        string $idempotencyKey,
    ): array {
        return $this->mutate(
            $actor,
            $context,
            [RawMaterialPermission::PurchasingCostsManage],
            'supplier-offer.price-event',
            $idempotencyKey,
            ['offer_id' => $offerId] + $input,
            function (string $operationId) use ($actor, $context, $offerId, $input): array {
                $offer = DB::table('supplier_offers')->where('organization_id', $actor->organization_id)
                    ->where('id', $offerId)->lockForUpdate()->first();
                if (! $offer || $offer->lifecycle_state !== 'ACTIVE') {
                    $this->notFound();
                }
                $product = DB::table('supplier_products as products')
                    ->join('organization_suppliers as suppliers', function ($join): void {
                        $join->on('suppliers.id', '=', 'products.supplier_id')
                            ->on('suppliers.organization_id', '=', 'products.organization_id');
                    })
                    ->join('raw_materials as materials', function ($join): void {
                        $join->on('materials.id', '=', 'products.raw_material_id')
                            ->on('materials.organization_id', '=', 'products.organization_id');
                    })
                    ->where('products.organization_id', $actor->organization_id)
                    ->where('products.id', $offer->supplier_product_id)->first([
                        'products.id', 'products.lifecycle_state', 'materials.lifecycle_state as material_state',
                        'materials.approved_revision_id', 'suppliers.lifecycle_state as supplier_state',
                    ]);
                if (! $product || $product->lifecycle_state !== 'ACTIVE') {
                    $this->notFound();
                }
                $latestRevision = DB::table('supplier_product_revisions')
                    ->where('organization_id', $actor->organization_id)
                    ->where('supplier_product_id', $product->id)->orderByDesc('revision_no')->first();
                if (! $latestRevision) {
                    $this->notFound();
                }
                if ($input['event_type'] === 'PRICE_SET'
                    && ($product->supplier_state !== 'ACTIVE' || $product->material_state !== 'ACTIVE'
                        || $product->approved_revision_id === null
                        || $latestRevision->raw_material_revision_id !== $product->approved_revision_id)) {
                    $this->notFound();
                }
                if ($input['event_type'] === 'PRICE_SET'
                    && ! DB::table('raw_materials_currency_codes')->where('currency_code', $input['currency_code'])
                        ->whereDate('valid_from', '<=', $input['valid_from'])
                        ->where(fn ($query) => $query->whereNull('valid_until')
                            ->orWhereDate('valid_until', '>', $input['valid_from']))->exists()) {
                    throw ValidationException::withMessages([
                        'currency_code' => [__('messages.raw_materials_currency_invalid')],
                    ]);
                }
                if ($input['event_type'] === 'WITHDRAWN') {
                    $this->withdrawOfferIfLatest(
                        $actor,
                        $product->id,
                        $offerId,
                        $input['supersedes_event_id'],
                        $input['valid_from'],
                    );
                }
                $eventNo = (int) DB::table('supplier_offer_events')->where('organization_id', $actor->organization_id)
                    ->where('offer_id', $offerId)->max('event_no') + 1;
                $id = Str::uuid()->toString();
                $payload = [
                    'id' => $id,
                    'organization_id' => $actor->organization_id,
                    'offer_id' => $offerId,
                    'supplier_product_id' => $product->id,
                    'supplier_product_revision_id' => $latestRevision->id,
                    'event_no' => $eventNo,
                    'event_type' => $input['event_type'],
                    'valid_from' => $input['valid_from'],
                    'valid_until' => $input['valid_until'] ?? null,
                    'net_amount_per_purchase_unit' => $input['net_amount_per_purchase_unit'] ?? null,
                    'currency_code' => $input['currency_code'] ?? null,
                    'minimum_order_quantity' => $input['minimum_order_quantity'] ?? null,
                    'quantity_from' => $input['quantity_from'] ?? null,
                    'quantity_until' => $input['quantity_until'] ?? null,
                    'source_code' => $input['source_code'] ?? null,
                    'source_reference' => $input['source_reference'] ?? null,
                    'supersedes_event_id' => $input['supersedes_event_id'] ?? null,
                    'recorded_by' => $actor->id,
                    'reason_code' => $input['reason_code'],
                    'operation_id' => $operationId,
                ];
                DB::table('supplier_offer_events')->insert($payload);
                if ($input['event_type'] !== 'WITHDRAWN') {
                    $this->normalizeOfferEvent($actor, $operationId, $payload, $latestRevision);
                }
                $this->audit($actor, $context, $operationId, 'SUPPLIER_OFFER', $offerId,
                    $input['event_type'] === 'WITHDRAWN' ? 'OFFER_WITHDRAWN' : 'OFFER_PRICE_SET',
                    null, ['event_id' => $id, 'event_no' => $eventNo, 'event_type' => $input['event_type']],
                    $input['reason_code']);

                $normalization = DB::table('supplier_offer_fx_normalization_events')->where('supplier_offer_event_id', $id)->first();

                return ['data' => [
                    'id' => $id,
                    'event_no' => $eventNo,
                    'event_type' => $input['event_type'],
                    'normalization_status' => $input['event_type'] === 'WITHDRAWN'
                        ? 'NOT_APPLICABLE' : ($normalization ? 'NORMALIZED' : 'SOURCE_ONLY'),
                ]];
            },
        );
    }

    public function exchangeRate(
        User $actor,
        Location $context,
        string $currencyCode,
        string $operationDate,
    ): array {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ExchangeRateView);
        $this->assertCurrencyDate($currencyCode, $operationDate);
        if ($currencyCode === 'PLN') {
            return ['data' => [
                'id' => null,
                'base_currency_code' => 'PLN',
                'quote_currency_code' => 'PLN',
                'pln_per_quote_unit' => '1',
                'operation_date' => $operationDate,
                'rate_date' => $operationDate,
                'source_code' => 'PLN_IDENTITY',
                'source_rate_id' => null,
                'revision_no' => 1,
            ]];
        }
        $rate = $this->effectiveRate($actor->organization_id, $currencyCode, $operationDate);
        if (! $rate) {
            $this->fxRequired($currencyCode);
        }

        return ['data' => $this->presentRate($rate, $operationDate)];
    }

    public function syncNbpRate(
        User $actor,
        Location $context,
        string $currencyCode,
        string $operationDate,
        string $idempotencyKey,
    ): array {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ExchangeRateManage);
        $this->assertCurrencyDate($currencyCode, $operationDate);
        if ($currencyCode === 'PLN') {
            throw ValidationException::withMessages([
                'currency_code' => [__('messages.raw_materials_pln_rate_not_importable')],
            ]);
        }
        $payload = ['currency_code' => $currencyCode, 'operation_date' => $operationDate];
        $replay = $this->existingIdempotencyResponse(
            $actor,
            $context,
            [RawMaterialPermission::ExchangeRateManage],
            'exchange-rate.nbp-sync',
            $idempotencyKey,
            $payload,
        );
        if ($replay !== null) {
            return $replay;
        }

        $tables = $this->fetchNbpTables($operationDate);
        $selected = $this->extractNbpRate($tables, $currencyCode);
        if ($selected === []) {
            $this->fxRequired($currencyCode);
        }

        return $this->mutate(
            $actor,
            $context,
            [RawMaterialPermission::ExchangeRateManage],
            'exchange-rate.nbp-sync',
            $idempotencyKey,
            $payload,
            function (string $operationId) use ($actor, $context, $selected, $currencyCode, $operationDate): array {
                $imported = [];
                foreach ($selected as $record) {
                    $existing = DB::table('organization_exchange_rate_events')
                        ->where('organization_id', $actor->organization_id)
                        ->where('quote_currency_code', $currencyCode)
                        ->where('rate_date', $record['rate_date'])
                        ->where('source_code', 'NBP_TABLE_A')
                        ->where('source_rate_id', $record['table_no'])
                        ->where('source_payload_hash', $record['hash'])
                        ->first();
                    if ($existing) {
                        $imported[] = $existing->id;

                        continue;
                    }
                    $revision = (int) DB::table('organization_exchange_rate_events')
                        ->where('organization_id', $actor->organization_id)
                        ->where('quote_currency_code', $currencyCode)
                        ->where('rate_date', $record['rate_date'])->max('revision_no') + 1;
                    $previous = DB::table('organization_exchange_rate_events')
                        ->where('organization_id', $actor->organization_id)
                        ->where('quote_currency_code', $currencyCode)
                        ->where('rate_date', $record['rate_date'])->orderByDesc('revision_no')->first();
                    $id = Str::uuid()->toString();
                    DB::table('organization_exchange_rate_events')->insert([
                        'id' => $id,
                        'organization_id' => $actor->organization_id,
                        'quote_currency_code' => $currencyCode,
                        'rate_date' => $record['rate_date'],
                        'pln_per_quote_unit' => $record['rate'],
                        'source_code' => 'NBP_TABLE_A',
                        'source_rate_id' => $record['table_no'],
                        'source_payload_hash' => $record['hash'],
                        'revision_no' => $revision,
                        'supersedes_id' => $previous?->id,
                        'operation_id' => $operationId,
                    ]);
                    $this->audit($actor, $context, $operationId, 'EXCHANGE_RATE', $id, 'FX_RATE_IMPORTED',
                        $previous ? ['id' => $previous->id, 'hash' => $previous->source_payload_hash] : null,
                        ['currency_code' => $currencyCode, 'rate_date' => $record['rate_date'], 'source' => 'NBP_TABLE_A']);
                    $imported[] = $id;
                }
                $rate = $this->effectiveRate($actor->organization_id, $currencyCode, $operationDate);

                return ['data' => [
                    'currency_code' => $currencyCode,
                    'operation_date' => $operationDate,
                    'imported_event_ids' => $imported,
                    'selected_rate' => $rate ? $this->presentRate($rate, $operationDate) : null,
                ]];
            },
        );
    }

    public function overrideExchangeRate(
        User $actor,
        Location $context,
        string $rateId,
        string $rateValue,
        string $reason,
        string $idempotencyKey,
    ): array {
        return $this->mutate(
            $actor,
            $context,
            [RawMaterialPermission::ExchangeRateManage],
            'exchange-rate.override',
            $idempotencyKey,
            ['rate_id' => $rateId, 'pln_per_quote_unit' => $rateValue, 'reason_code' => $reason],
            function (string $operationId) use ($actor, $context, $rateId, $rateValue, $reason): array {
                $base = DB::table('organization_exchange_rate_events')->where('organization_id', $actor->organization_id)
                    ->where('id', $rateId)->lockForUpdate()->first();
                if (! $base || $base->source_code !== 'NBP_TABLE_A') {
                    $this->notFound();
                }
                $revision = (int) DB::table('organization_exchange_rate_events')
                    ->where('organization_id', $actor->organization_id)
                    ->where('quote_currency_code', $base->quote_currency_code)
                    ->where('rate_date', $base->rate_date)->max('revision_no') + 1;
                $previous = DB::table('organization_exchange_rate_events')
                    ->where('organization_id', $actor->organization_id)
                    ->where('quote_currency_code', $base->quote_currency_code)
                    ->where('rate_date', $base->rate_date)->orderByDesc('revision_no')->first();
                $id = Str::uuid()->toString();
                DB::table('organization_exchange_rate_events')->insert([
                    'id' => $id,
                    'organization_id' => $actor->organization_id,
                    'quote_currency_code' => $base->quote_currency_code,
                    'rate_date' => $base->rate_date,
                    'pln_per_quote_unit' => $rateValue,
                    'source_code' => 'MANUAL_OVERRIDE',
                    'revision_no' => $revision,
                    'override_of_id' => $base->id,
                    'supersedes_id' => $previous?->id,
                    'recorded_by' => $actor->id,
                    'reason_code' => $reason,
                    'operation_id' => $operationId,
                ]);
                $this->audit($actor, $context, $operationId, 'EXCHANGE_RATE', $id, 'FX_RATE_OVERRIDE_CREATED',
                    ['id' => $previous?->id, 'hash' => $previous ? $this->hash($previous) : null],
                    ['currency_code' => $base->quote_currency_code, 'rate_date' => $base->rate_date, 'source' => 'MANUAL_OVERRIDE'],
                    $reason);
                $rate = DB::table('organization_exchange_rate_events')->where('id', $id)->first();

                return ['data' => $this->presentRate($rate, $base->rate_date)];
            },
        );
    }

    public function referencePrices(
        User $actor,
        Location $context,
        string $materialId,
        ?string $asOf,
    ): array {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceView);
        $this->material($actor, $materialId);
        $query = DB::table('raw_material_reference_price_events')
            ->where('organization_id', $actor->organization_id)->where('raw_material_id', $materialId);
        if ($asOf !== null) {
            $query->whereDate('effective_from', '<=', $asOf);
        }
        $rows = $query->orderByDesc('effective_from')->orderByDesc('event_no')->get();

        $canViewPurchasingCosts = $this->authorization->hasEffectiveGrant(
            $actor,
            RawMaterialPermission::PurchasingCostsView,
        );

        return ['data' => $rows->map(
            fn (object $row): array => $this->presentReferencePrice($row, $canViewPurchasingCosts),
        )->all()];
    }

    public function setReferencePrice(
        User $actor,
        Location $context,
        string $materialId,
        array $input,
        string $idempotencyKey,
    ): array {
        $permissions = [RawMaterialPermission::ReferencePriceManage];
        if (filled($input['source_offer_event_id'] ?? null)) {
            $permissions[] = RawMaterialPermission::PurchasingCostsView;
        }

        return $this->mutate(
            $actor,
            $context,
            $permissions,
            'reference-price.event',
            $idempotencyKey,
            ['material_id' => $materialId] + $input,
            function (string $operationId) use ($actor, $context, $materialId, $input): array {
                $material = $this->material($actor, $materialId, activeOnly: true);
                $latestNo = (int) DB::table('raw_material_reference_price_events')
                    ->where('organization_id', $actor->organization_id)->where('raw_material_id', $materialId)->max('event_no');
                if (($input['expected_event_no'] ?? -1) !== $latestNo) {
                    $this->conflict('RAW_MATERIAL_VERSION_CONFLICT', 'Historia ceny zmieniła się. Odśwież widok i spróbuj ponownie.');
                }
                $id = Str::uuid()->toString();
                $event = [
                    'id' => $id,
                    'organization_id' => $actor->organization_id,
                    'raw_material_id' => $material->id,
                    'event_no' => $latestNo + 1,
                    'event_type' => $input['event_type'],
                    'effective_from' => $input['effective_from'],
                    'net_pln_per_base_unit' => $input['net_pln_per_base_unit'] ?? null,
                    'reason_code' => $input['reason_code'],
                    'recorded_by' => $actor->id,
                    'operation_id' => $operationId,
                ];
                $sourceAudit = null;
                if ($input['event_type'] === 'PRICE_SET' && filled($input['source_offer_event_id'] ?? null)) {
                    [$sourceFields, $sourceAudit] = $this->referencePriceSource($actor, $material, $input);
                    $event = array_merge($event, $sourceFields);
                    $event['net_pln_per_base_unit'] = $sourceFields['computed_net_pln_per_base_unit'];
                    unset($event['computed_net_pln_per_base_unit']);
                    $matchesCalculatedPrice = DB::selectOne(
                        'SELECT CAST(? AS numeric) = CAST(? AS numeric) AS matches',
                        [$event['net_pln_per_base_unit'], $input['net_pln_per_base_unit']],
                    )->matches;
                    if (! $matchesCalculatedPrice) {
                        $this->conflict('RAW_MATERIAL_REFERENCE_PRICE_CALCULATION_MISMATCH',
                            'Cena referencyjna nie zgadza się z wybraną ofertą i kursem.');
                    }
                }
                DB::table('raw_material_reference_price_events')->insert($event);
                $this->audit($actor, $context, $operationId, 'RAW_MATERIAL_REFERENCE_PRICE', $id,
                    $input['event_type'] === 'PRICE_WITHDRAWN' ? 'REFERENCE_PRICE_WITHDRAWN' : 'REFERENCE_PRICE_SET',
                    null, ['raw_material_id' => $materialId, 'event_no' => $latestNo + 1] + ($sourceAudit ?? []),
                    $input['reason_code']);
                $row = DB::table('raw_material_reference_price_events')->where('id', $id)->first();

                return ['data' => $this->presentReferencePrice(
                    $row,
                    filled($input['source_offer_event_id'] ?? null),
                )];
            },
        );
    }

    public function createRestatement(
        User $actor,
        Location $context,
        string $materialId,
        string $eventId,
        array $input,
        string $idempotencyKey,
    ): array {
        return $this->mutate(
            $actor,
            $context,
            [RawMaterialPermission::ReferencePriceManage],
            'reference-price.restatement',
            $idempotencyKey,
            ['material_id' => $materialId, 'event_id' => $eventId] + $input,
            function (string $operationId) use ($actor, $context, $materialId, $eventId, $input): array {
                $this->material($actor, $materialId);
                $original = DB::table('raw_material_reference_price_events')->where('organization_id', $actor->organization_id)
                    ->where('raw_material_id', $materialId)->where('id', $eventId)->first();
                if (! $original || $original->event_type !== 'PRICE_SET'
                    || $original->effective_from !== $input['operation_date']) {
                    $this->notFound();
                }
                $id = Str::uuid()->toString();
                DB::table('raw_material_financial_restatements')->insert([
                    'id' => $id,
                    'organization_id' => $actor->organization_id,
                    'raw_material_id' => $materialId,
                    'original_reference_price_event_id' => $eventId,
                    'original_snapshot_hash' => str_repeat('0', 64),
                    'restated_net_pln_per_base_unit' => $input['restated_net_pln_per_base_unit'],
                    'operation_date' => $input['operation_date'],
                    'reason_code' => $input['reason_code'],
                    'recorded_by' => $actor->id,
                    'operation_id' => $operationId,
                ]);
                $row = DB::table('raw_material_financial_restatements')->where('id', $id)->first();
                $this->audit($actor, $context, $operationId, 'FINANCIAL_RESTATEMENT', $id,
                    'FINANCIAL_RESTATEMENT_CREATED',
                    ['event_id' => $eventId, 'hash' => $original->original_snapshot_hash ?? null],
                    ['event_id' => $eventId, 'snapshot_hash' => $row->original_snapshot_hash], $input['reason_code']);

                return ['data' => [
                    'id' => $id,
                    'original_reference_price_event_id' => $eventId,
                    'original_snapshot_hash' => $row->original_snapshot_hash,
                    'restated_net_pln_per_base_unit' => (string) $row->restated_net_pln_per_base_unit,
                    'currency_code' => 'PLN',
                    'operation_date' => $row->operation_date,
                ]];
            },
        );
    }

    public function referencePriceRestatements(
        User $actor,
        Location $context,
        string $materialId,
        string $eventId,
    ): array {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceView);
        $this->material($actor, $materialId);
        if (! DB::table('raw_material_reference_price_events')->where('organization_id', $actor->organization_id)
            ->where('raw_material_id', $materialId)->where('id', $eventId)->exists()) {
            $this->notFound();
        }

        return ['data' => DB::table('raw_material_financial_restatements')
            ->where('organization_id', $actor->organization_id)
            ->where('raw_material_id', $materialId)
            ->where('original_reference_price_event_id', $eventId)
            ->orderBy('recorded_at')->orderBy('id')
            ->get(['id', 'original_reference_price_event_id', 'original_snapshot_hash',
                'restated_net_pln_per_base_unit', 'currency_code', 'operation_date', 'reason_code', 'recorded_at'])
            ->map(function (object $row): array {
                $row->restated_net_pln_per_base_unit = (string) $row->restated_net_pln_per_base_unit;

                return (array) $row;
            })->all()];
    }

    private function mutate(
        User $actor,
        Location $context,
        array $permissions,
        string $operationCode,
        string $idempotencyKey,
        array $payload,
        callable $operation,
    ): array {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,120}$/D', $idempotencyKey)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => [__('messages.raw_material_idempotency_key_required')],
            ]);
        }
        $permissions = array_values(array_unique($permissions, SORT_REGULAR));
        foreach ($permissions as $permission) {
            $this->authorization->assertAllowed($actor, $context, $permission);
        }
        $requestHash = hash_hmac(
            'sha256',
            json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            (string) config('app.key'),
        );

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialCommercial(), $actor->id, fn (): array => DB::transaction(function () use (
            $actor, $context, $permissions, $operationCode, $idempotencyKey, $payload, $operation, $requestHash,
        ): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            foreach ($permissions as $permission) {
                $this->authorization->assertAllowed($actor, $context, $permission);
            }
            $prior = DB::table('raw_materials_commercial_idempotency')
                ->where('organization_id', $actor->organization_id)
                ->where('actor_user_id', $actor->id)
                ->where('operation_code', $operationCode)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($prior) {
                $response = $this->decodeIdempotencyResponse($prior);
                if (! hash_equals($prior->request_hash, $requestHash)
                    && ! $this->matchesNbpSyncIntent($operationCode, $response, $payload)) {
                    $this->conflict('RAW_MATERIAL_IDEMPOTENCY_CONFLICT',
                        'Klucz idempotencji został już użyty dla innego żądania.');
                }

                return $response;
            }
            $operationId = Str::uuid()->toString();
            $response = $operation($operationId);
            DB::table('raw_materials_commercial_idempotency')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $actor->organization_id,
                'actor_user_id' => $actor->id,
                'operation_code' => $operationCode,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'response_body' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);

            return $response;
        }, attempts: 1));
    }

    private function existingIdempotencyResponse(
        User $actor,
        Location $context,
        array $permissions,
        string $operationCode,
        string $idempotencyKey,
        array $payload,
    ): ?array {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,120}$/D', $idempotencyKey)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => [__('messages.raw_material_idempotency_key_required')],
            ]);
        }
        foreach ($permissions as $permission) {
            $this->authorization->assertAllowed($actor, $context, $permission);
        }
        $requestHash = hash_hmac(
            'sha256',
            json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            (string) config('app.key'),
        );

        return DB::transaction(function () use (
            $actor, $context, $permissions, $operationCode, $idempotencyKey, $payload, $requestHash,
        ): ?array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            foreach ($permissions as $permission) {
                $this->authorization->assertAllowed($actor, $context, $permission);
            }
            $prior = DB::table('raw_materials_commercial_idempotency')
                ->where('organization_id', $actor->organization_id)
                ->where('actor_user_id', $actor->id)
                ->where('operation_code', $operationCode)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if (! $prior) {
                return null;
            }
            if (! hash_equals($prior->request_hash, $requestHash)) {
                $response = $this->decodeIdempotencyResponse($prior);
                if (! $this->matchesNbpSyncIntent($operationCode, $response, $payload)) {
                    $this->conflict('RAW_MATERIAL_IDEMPOTENCY_CONFLICT',
                        'Klucz idempotencji został już użyty dla innego żądania.');
                }

                return $response;
            }

            return $this->decodeIdempotencyResponse($prior);
        }, attempts: 1);
    }

    private function matchesNbpSyncIntent(string $operationCode, array $response, array $payload): bool
    {
        return $operationCode === 'exchange-rate.nbp-sync'
            && ($response['data']['currency_code'] ?? null) === ($payload['currency_code'] ?? null)
            && ($response['data']['operation_date'] ?? null) === ($payload['operation_date'] ?? null);
    }

    private function decodeIdempotencyResponse(object $prior): array
    {
        return is_array($prior->response_body)
            ? $prior->response_body
            : json_decode($prior->response_body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function insertProductRevision(
        User $actor,
        string $productId,
        object $material,
        array $input,
        int $revisionNo,
    ): object {
        $unit = DB::table('raw_material_revision_units')
            ->where('organization_id', $actor->organization_id)
            ->where('revision_id', $input['raw_material_revision_id'])
            ->where('unit_code', $input['content_unit_code'])->first();
        if (! $unit) {
            throw ValidationException::withMessages([
                'content_unit_code' => [__('messages.raw_material_unit_invalid')],
            ]);
        }
        $id = Str::uuid()->toString();
        DB::table('supplier_product_revisions')->insert([
            'id' => $id,
            'organization_id' => $actor->organization_id,
            'supplier_product_id' => $productId,
            'revision_no' => $revisionNo,
            'raw_material_id' => $material->id,
            'raw_material_revision_id' => $input['raw_material_revision_id'],
            'raw_material_revision_unit_id' => $unit->id,
            'supplier_sku' => filled($input['supplier_sku'] ?? null) ? trim($input['supplier_sku']) : null,
            'gtin' => $input['gtin'] ?? null,
            'product_name' => trim($input['product_name']),
            'purchase_unit_code' => $input['purchase_unit_code'],
            'content_quantity' => $input['content_quantity'],
            'content_unit_code' => $input['content_unit_code'],
            'to_base_factor_snapshot' => '1',
            'base_quantity_snapshot' => '1',
            'content_hash' => str_repeat('0', 64),
            'created_by' => $actor->id,
        ]);

        return DB::table('supplier_product_revisions')->where('id', $id)->first();
    }

    private function normalizeOfferEvent(User $actor, string $operationId, array $event, object $revision): void
    {
        $rate = $event['currency_code'] === 'PLN'
            ? null
            : $this->effectiveRate($actor->organization_id, $event['currency_code'], $event['valid_from']);
        if ($event['currency_code'] !== 'PLN' && ! $rate) {
            return;
        }
        $rateValue = $rate ? (string) $rate->pln_per_quote_unit : '1';
        $unitAmount = DB::selectOne(
            'SELECT raw_materials_round_half_even_v1(CAST(? AS numeric) * CAST(? AS numeric), 14) AS amount',
            [$event['net_amount_per_purchase_unit'], $rateValue],
        )->amount;
        $baseAmount = DB::selectOne(
            'SELECT raw_materials_round_half_even_v1(CAST(? AS numeric) * CAST(? AS numeric) / CAST(? AS numeric), 14) AS amount',
            [$event['net_amount_per_purchase_unit'], $rateValue, (string) $revision->base_quantity_snapshot],
        )->amount;
        DB::table('supplier_offer_fx_normalization_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'supplier_offer_event_id' => $event['id'],
            'exchange_rate_event_id' => $rate?->id,
            'rate_snapshot' => $rateValue,
            'operation_date' => $event['valid_from'],
            'rate_date' => $rate?->rate_date,
            'source_code' => $rate?->source_code ?? 'PLN_IDENTITY',
            'reporting_amount_pln_per_purchase_unit' => (string) $unitAmount,
            'reporting_amount_pln_per_base_unit' => (string) $baseAmount,
            'recorded_by' => $actor->id,
            'operation_id' => $operationId,
        ]);
    }

    private function withdrawOfferIfLatest(
        User $actor,
        string $productId,
        string $offerId,
        string $eventId,
        string $withdrawalDate,
    ): void {
        $event = DB::table('supplier_offer_events')->where('organization_id', $actor->organization_id)
            ->where('id', $eventId)->where('offer_id', $offerId)->where('event_type', 'PRICE_SET')->first();
        $latest = DB::table('supplier_offer_events')->where('organization_id', $actor->organization_id)
            ->where('offer_id', $offerId)->orderByDesc('event_no')->first();
        if (! $event || ! $latest || $latest->id !== $eventId || $event->valid_from > $withdrawalDate
            || $event->supplier_product_id !== $productId
            || DB::table('supplier_offer_events')->where('organization_id', $actor->organization_id)
                ->where('supersedes_event_id', $eventId)->exists()) {
            $this->conflict('RAW_MATERIAL_OFFER_WITHDRAWAL_INVALID', 'Nie można wycofać wskazanego zdarzenia oferty.');
        }
    }

    private function referencePriceSource(User $actor, object $material, array $input): array
    {
        $event = DB::table('supplier_offer_events')->where('organization_id', $actor->organization_id)
            ->where('id', $input['source_offer_event_id'])->first();
        if (! $event || $event->event_type !== 'PRICE_SET' || $event->valid_from > $input['effective_from']
            || ($event->valid_until !== null && $event->valid_until <= $input['effective_from'])) {
            $this->notFound();
        }
        if (DB::table('supplier_offer_events')->where('organization_id', $actor->organization_id)
            ->where('supersedes_event_id', $event->id)
            ->whereDate('valid_from', '<=', $input['effective_from'])->exists()) {
            $this->notFound();
        }
        $offer = DB::table('supplier_offers')->where('organization_id', $actor->organization_id)
            ->where('id', $event->offer_id)->where('lifecycle_state', 'ACTIVE')->first();
        $productRevision = DB::table('supplier_product_revisions as revisions')
            ->join('supplier_products as products', function ($join): void {
                $join->on('products.id', '=', 'revisions.supplier_product_id')
                    ->on('products.organization_id', '=', 'revisions.organization_id');
            })
            ->join('organization_suppliers as suppliers', function ($join): void {
                $join->on('suppliers.id', '=', 'products.supplier_id')
                    ->on('suppliers.organization_id', '=', 'products.organization_id');
            })
            ->join('raw_materials as materials', function ($join): void {
                $join->on('materials.id', '=', 'products.raw_material_id')
                    ->on('materials.organization_id', '=', 'products.organization_id');
            })
            ->where('revisions.organization_id', $actor->organization_id)
            ->where('revisions.id', $event->supplier_product_revision_id)
            ->where('revisions.raw_material_id', $material->id)
            ->select([
                'revisions.*',
                'products.lifecycle_state as product_state',
                'suppliers.lifecycle_state as supplier_state',
                'materials.lifecycle_state as material_state',
                'materials.approved_revision_id',
            ])->first();
        $latestRevisionId = $productRevision
            ? DB::table('supplier_product_revisions')->where('organization_id', $actor->organization_id)
                ->where('supplier_product_id', $productRevision->supplier_product_id)->orderByDesc('revision_no')
                ->value('id')
            : null;
        if (! $offer || ! $productRevision || $offer->supplier_product_id !== $productRevision->supplier_product_id
            || $productRevision->product_state !== 'ACTIVE' || $productRevision->supplier_state !== 'ACTIVE'
            || $productRevision->material_state !== 'ACTIVE'
            || $productRevision->approved_revision_id !== $productRevision->raw_material_revision_id
            || $productRevision->id !== $latestRevisionId) {
            $this->notFound();
        }
        $rate = $event->currency_code === 'PLN'
            ? null
            : $this->effectiveRate($actor->organization_id, $event->currency_code, $input['effective_from']);
        if ($event->currency_code !== 'PLN' && ! $rate) {
            $this->fxRequired($event->currency_code);
        }
        $rateValue = $rate ? (string) $rate->pln_per_quote_unit : '1';
        $price = DB::selectOne(
            'SELECT raw_materials_round_half_even_v1(CAST(? AS numeric) * CAST(? AS numeric) / CAST(? AS numeric), 14) AS amount',
            [$event->net_amount_per_purchase_unit, $rateValue, (string) $productRevision->base_quantity_snapshot],
        )->amount;

        return [[
            'source_net_amount' => (string) $event->net_amount_per_purchase_unit,
            'source_currency_code' => $event->currency_code,
            'source_offer_event_id' => $event->id,
            'source_product_revision_id' => $productRevision->id,
            'exchange_rate_event_id' => $rate?->id,
            'rate_snapshot' => $rateValue,
            'operation_date' => $input['effective_from'],
            'rate_date' => $rate?->rate_date,
            'source_code' => $rate?->source_code ?? 'PLN_IDENTITY',
            'computed_net_pln_per_base_unit' => (string) $price,
        ], [
            'source_offer_event_id' => $event->id,
            'source_product_revision_id' => $productRevision->id,
            'exchange_rate_event_id' => $rate?->id,
        ]];
    }

    private function fetchNbpTables(string $operationDate): array
    {
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $operationDate, 'Europe/Warsaw');
        $start = $end->subDays(10);
        try {
            $response = Http::acceptJson()->timeout(15)->get(
                'https://api.nbp.pl/api/exchangerates/tables/A/'.$start->toDateString().'/'.$end->toDateString().'/',
                ['format' => 'json'],
            );
        } catch (ConnectionException) {
            throw new RawMaterialException(
                'RAW_MATERIALS_NBP_UNAVAILABLE',
                __('messages.raw_materials_nbp_unavailable'),
                502,
            );
        }
        if (! $response->successful()) {
            throw new RawMaterialException(
                'RAW_MATERIALS_NBP_UNAVAILABLE',
                __('messages.raw_materials_nbp_unavailable'),
                502,
            );
        }
        try {
            $tables = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $tables = null;
        }
        if (! is_array($tables) || ! array_is_list($tables)) {
            throw new RawMaterialException(
                'RAW_MATERIALS_NBP_RESPONSE_INVALID',
                __('messages.raw_materials_nbp_response_invalid'),
                502,
            );
        }

        preg_match_all(
            '/\{\s*"currency"\s*:\s*"(?:[^"\\\\]|\\\\.)*"\s*,\s*"code"\s*:\s*"([A-Z]{3})"\s*,\s*"mid"\s*:\s*(\d{1,12}(?:\.\d{1,12})?)\s*\}/',
            $response->body(),
            $exactRates,
            PREG_SET_ORDER,
        );
        $rateCount = array_sum(array_map(
            static fn (mixed $table): int => is_array($table) && is_array($table['rates'] ?? null)
                ? count($table['rates']) : 0,
            $tables,
        ));
        if ($rateCount !== count($exactRates)) {
            throw new RawMaterialException(
                'RAW_MATERIALS_NBP_RESPONSE_INVALID',
                __('messages.raw_materials_nbp_response_invalid'),
                502,
            );
        }
        $rateIndex = 0;

        return array_map(function (mixed $table) use ($response, $exactRates, &$rateIndex): array {
            if (! is_array($table) || ($table['table'] ?? null) !== 'A'
                || ! is_string($table['no'] ?? null)
                || ! preg_match('/^\\d{3}\\/A\\/NBP\\/\\d{4}$/D', $table['no'])
                || ! is_string($table['effectiveDate'] ?? null)
                || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $table['effectiveDate'])
                || ! is_array($table['rates'] ?? null)) {
                throw new RawMaterialException(
                    'RAW_MATERIALS_NBP_RESPONSE_INVALID',
                    __('messages.raw_materials_nbp_response_invalid'),
                    502,
                );
            }
            $rates = [];
            foreach ($table['rates'] as $rate) {
                $exactRate = $exactRates[$rateIndex++] ?? null;
                if (! is_array($rate) || ! is_string($rate['code'] ?? null)
                    || ! preg_match('/^[A-Z]{3}$/D', $rate['code'])
                    || ! is_array($exactRate) || ($exactRate[1] ?? null) !== $rate['code']
                    || ! preg_match('/^\\d{1,12}(?:\\.\\d{1,12})?$/D', $exactRate[2] ?? '')) {
                    throw new RawMaterialException(
                        'RAW_MATERIALS_NBP_RESPONSE_INVALID',
                        __('messages.raw_materials_nbp_response_invalid'),
                        502,
                    );
                }
                $rates[$rate['code']] = $exactRate[2];
            }

            return [
                'table_no' => $table['no'],
                'rate_date' => $table['effectiveDate'],
                'rates' => $rates,
                'hash' => hash('sha256', $response->body()),
            ];
        }, $tables);
    }

    private function extractNbpRate(array $tables, string $currencyCode): array
    {
        $records = [];
        foreach ($tables as $table) {
            if (isset($table['rates'][$currencyCode])) {
                $records[] = [
                    'table_no' => $table['table_no'],
                    'rate_date' => $table['rate_date'],
                    'rate' => $table['rates'][$currencyCode],
                    'hash' => $table['hash'],
                ];
            }
        }

        return $records;
    }

    private function effectiveRate(string $organizationId, string $currencyCode, string $operationDate): ?object
    {
        return DB::table('organization_exchange_rate_events')
            ->where('organization_id', $organizationId)
            ->where('quote_currency_code', $currencyCode)
            ->whereDate('rate_date', '<=', $operationDate)
            ->orderByDesc('rate_date')
            ->orderByRaw("CASE WHEN source_code = 'MANUAL_OVERRIDE' THEN 1 ELSE 0 END DESC")
            ->orderByDesc('revision_no')
            ->first();
    }

    private function presentRate(object $rate, string $operationDate): array
    {
        return [
            'id' => $rate->id,
            'base_currency_code' => 'PLN',
            'quote_currency_code' => $rate->quote_currency_code,
            'pln_per_quote_unit' => (string) $rate->pln_per_quote_unit,
            'operation_date' => $operationDate,
            'rate_date' => $rate->rate_date,
            'source_code' => $rate->source_code,
            'source_rate_id' => $rate->source_rate_id,
            'revision_no' => $rate->revision_no,
        ];
    }

    private function presentReferencePrice(object $row, bool $includePurchasingSource = false): array
    {
        return [
            'id' => $row->id,
            'event_no' => $row->event_no,
            'event_type' => $row->event_type,
            'effective_from' => $row->effective_from,
            'net_pln_per_base_unit' => $row->net_pln_per_base_unit === null
                ? null : (string) $row->net_pln_per_base_unit,
            'currency_code' => 'PLN',
            'reason_code' => $row->reason_code,
            'exchange_rate_event_id' => $row->exchange_rate_event_id,
            'rate_snapshot' => $row->rate_snapshot === null ? null : (string) $row->rate_snapshot,
            'rate_date' => $row->rate_date,
            'source_code' => $row->source_code,
            'source' => $row->source_net_amount === null || ! $includePurchasingSource ? null : [
                'amount' => (string) $row->source_net_amount,
                'currency' => $row->source_currency_code,
                'offer_event_id' => $row->source_offer_event_id,
                'exchange_rate_event_id' => $row->exchange_rate_event_id,
                'pln_per_currency_unit' => $row->rate_snapshot === null ? null : (string) $row->rate_snapshot,
                'operation_date' => $row->operation_date,
                'rate_date' => $row->rate_date,
                'source_code' => $row->source_code,
                'rounding' => 'HALF_EVEN',
            ],
        ];
    }

    private function supplier(User $actor, string $supplierId): array
    {
        $supplier = DB::table('organization_suppliers')->where('organization_id', $actor->organization_id)
            ->where('id', $supplierId)
            ->first(['id', 'supplier_code', 'display_name', 'legal_name', 'lifecycle_state', 'created_at']);
        if (! $supplier) {
            $this->notFound();
        }

        return (array) $supplier;
    }

    private function presentProduct(User $actor, string $productId): array
    {
        $product = DB::table('supplier_products')->where('organization_id', $actor->organization_id)
            ->where('id', $productId)->first();
        if (! $product) {
            $this->notFound();
        }
        $revision = DB::table('supplier_product_revisions')->where('organization_id', $actor->organization_id)
            ->where('supplier_product_id', $productId)->orderByDesc('revision_no')->first();
        if (! $revision) {
            $this->notFound();
        }

        return [
            'id' => $product->id,
            'supplier_id' => $product->supplier_id,
            'raw_material_id' => $product->raw_material_id,
            'lifecycle_state' => $product->lifecycle_state,
            'current_revision' => [
                'id' => $revision->id,
                'revision_no' => $revision->revision_no,
                'raw_material_revision_id' => $revision->raw_material_revision_id,
                'product_name' => $revision->product_name,
                'supplier_sku' => $revision->supplier_sku,
                'gtin' => $revision->gtin,
                'purchase_unit_code' => $revision->purchase_unit_code,
                'content_quantity' => (string) $revision->content_quantity,
                'content_unit_code' => $revision->content_unit_code,
                'to_base_factor' => (string) $revision->to_base_factor_snapshot,
                'base_quantity' => (string) $revision->base_quantity_snapshot,
                'compatibility_status' => 'PENDING',
            ],
        ];
    }

    private function material(User $actor, string $materialId, bool $activeOnly = false): ?object
    {
        $query = DB::table('raw_materials')->where('organization_id', $actor->organization_id)->where('id', $materialId);
        if ($activeOnly) {
            $query->where('lifecycle_state', 'ACTIVE')->whereNotNull('approved_revision_id');
        }
        $material = $query->first();
        if (! $material) {
            $this->notFound();
        }

        return $material;
    }

    private function assertCurrencyDate(string $currencyCode, string $operationDate): void
    {
        $exists = DB::table('raw_materials_currency_codes')->where('currency_code', $currencyCode)
            ->whereDate('valid_from', '<=', $operationDate)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>', $operationDate))
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'currency_code' => [__('messages.raw_materials_currency_invalid')],
            ]);
        }
    }

    private function audit(
        User $actor,
        Location $context,
        string $operationId,
        string $subjectType,
        string $subjectId,
        string $eventCode,
        mixed $before,
        mixed $after,
        ?string $reason = null,
    ): void {
        DB::table('raw_materials_commercial_audit_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'operation_id' => $operationId,
            'actor_user_id' => $actor->id,
            'actor_location_id' => $context->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event_code' => $eventCode,
            'outcome' => 'ACCEPTED',
            'reason_code' => $reason ? 'USER_PROVIDED' : null,
            'before_hash' => $before === null ? null : $this->hash($before),
            'after_hash' => $after === null ? null : $this->hash($after),
            'metadata' => json_encode([
                'reason' => $reason,
                'record' => $this->safeMetadata($after),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function safeMetadata(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        $safe = [];
        foreach ($value as $key => $item) {
            if (preg_match('/amount|price|rate|tax|currency|password|token/i', (string) $key)) {
                continue;
            }
            $safe[$key] = is_array($item) || is_object($item) ? $this->safeMetadata($item) : $item;
        }

        return $safe;
    }

    private function hash(mixed $value): string
    {
        $canonical = $this->canonicalize($value);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function notFound(): never
    {
        throw new RawMaterialException('RAW_MATERIAL_RESOURCE_NOT_FOUND', __('messages.resource_not_found'), 404);
    }

    private function conflict(string $code, string $message): never
    {
        throw new RawMaterialException($code, $message, 409);
    }

    private function fxRequired(string $currencyCode): never
    {
        throw new RawMaterialException(
            'RAW_MATERIALS_FX_RATE_REQUIRED',
            __('messages.raw_materials_fx_rate_required', ['currency_code' => $currencyCode]),
            409,
            ['currency_code' => $currencyCode],
        );
    }
}
