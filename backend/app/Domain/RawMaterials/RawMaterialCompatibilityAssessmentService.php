<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RawMaterialCompatibilityAssessmentService
{
    public function __construct(
        private readonly RawMaterialAuthorization $authorization,
        private readonly RawMaterialLocationSharePolicy $sharePolicy,
        private readonly AuthorizationMutationContext $contexts,
    ) {}

    public function history(User $actor, Location $context, string $productId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::SupplierView);
        $product = $this->product($actor, $productId);
        $this->assertMaterialSharedForConsumer($actor, $context, $product->raw_material_id);
        $isPrimary = $this->sharePolicy->isPrimaryContext($context);
        $status = DB::table('raw_material_supplier_compatibility_status')
            ->where('organization_id', $actor->organization_id)
            ->where('supplier_product_id', $product->id)
            ->first();
        $assessmentContext = $this->assessmentContext($actor, $product, $status);
        $history = DB::table('raw_material_supplier_compatibility_assessments')
            ->where('organization_id', $actor->organization_id)
            ->where('supplier_product_id', $product->id);
        if (! $isPrimary) {
            $approvedRevisionId = DB::table('raw_materials')
                ->where('organization_id', $actor->organization_id)
                ->where('id', $product->raw_material_id)
                ->value('approved_revision_id');
            $history->where('raw_material_revision_id', $approvedRevisionId);
            if (($assessmentContext['raw_material_revision_current'] ?? false) !== true) {
                $assessmentContext = null;
                $status = null;
            }
        }

        return [
            'supplier_product_id' => $product->id,
            'status' => $status?->status ?? 'PENDING',
            'current_supplier_product_revision_id' => $status?->supplier_product_revision_id,
            'current_assessment_id' => $status?->current_assessment_id,
            'assessment_context' => $assessmentContext,
            'history' => $history
                ->orderByDesc('assessment_number')
                ->get([
                    'id',
                    'assessment_number',
                    'supplier_product_revision_id',
                    'raw_material_revision_id',
                    'allergen_catalog_version_id',
                    'document_version_id',
                    'document_sha256',
                    'outcome',
                    'decision_reason',
                    'assessed_by',
                    'assessed_at',
                ])
                ->map(fn (object $assessment): array => [
                    'id' => $assessment->id,
                    'assessment_number' => (int) $assessment->assessment_number,
                    'supplier_product_revision_id' => $assessment->supplier_product_revision_id,
                    'raw_material_revision_id' => $assessment->raw_material_revision_id,
                    'allergen_catalog_version_id' => $assessment->allergen_catalog_version_id,
                    'document_version_id' => $assessment->document_version_id,
                    'document_sha256' => $assessment->document_sha256,
                    'outcome' => $assessment->outcome,
                    'decision_reason' => $assessment->decision_reason,
                    'assessed_by' => $assessment->assessed_by,
                    'assessed_at' => $assessment->assessed_at,
                    'applicability' => $assessment->id === $status?->current_assessment_id ? 'CURRENT' : 'HISTORICAL',
                ])->all(),
        ];
    }

    public function assess(
        User $actor,
        Location $context,
        string $productId,
        array $input,
        string $idempotencyKey,
    ): array {
        $this->validateDecision($input, $idempotencyKey);
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::SupplierCompatibilityApprove);

        $payload = [
            'supplier_product_id' => $productId,
            'document_version_id' => $input['document_version_id'],
            'outcome' => $input['outcome'],
            'decision_reason' => trim($input['decision_reason']),
            'allergen_declaration_reviewed' => true,
        ];
        $requestHash = hash_hmac(
            'sha256',
            json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            (string) config('app.key'),
        );

        return $this->contexts->run(
            AuthorizationMutationScope::rawMaterialCommercial(),
            $actor->id,
            fn (): array => DB::transaction(function () use (
                $actor,
                $context,
                $productId,
                $payload,
                $idempotencyKey,
                $requestHash,
            ): array {
                Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
                $this->authorization->assertAllowed(
                    $actor,
                    $context,
                    RawMaterialPermission::SupplierCompatibilityApprove,
                );
                $product = $this->product($actor, $productId, true);
                $this->assertMaterialSharedForConsumer($actor, $context, $product->raw_material_id);

                $prior = DB::table('raw_materials_commercial_idempotency')
                    ->where('organization_id', $actor->organization_id)
                    ->where('actor_user_id', $actor->id)
                    ->where('operation_code', 'supplier-compatibility.assess')
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($prior) {
                    if (! hash_equals($prior->request_hash, $requestHash)) {
                        throw new RawMaterialException(
                            'RAW_MATERIAL_IDEMPOTENCY_CONFLICT',
                            __('messages.raw_material_idempotency_conflict'),
                        );
                    }

                    return is_array($prior->response_body)
                        ? $prior->response_body
                        : json_decode($prior->response_body, true, 512, JSON_THROW_ON_ERROR);
                }

                $current = $this->currentRevisionContext($actor, $product);
                $evidence = DB::table('raw_material_approved_compatibility_document_evidence')
                    ->where('organization_id', $actor->organization_id)
                    ->where('document_version_id', $payload['document_version_id'])
                    ->where('supplier_product_revision_id', $current['supplier_product_revision_id'])
                    ->where('raw_material_revision_id', $current['raw_material_revision_id'])
                    ->first(['document_version_id', 'sha256']);
                if (! $evidence) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_COMPATIBILITY_EVIDENCE_INVALID',
                        __('messages.raw_material_compatibility_evidence_invalid'),
                        409,
                    );
                }

                $assessmentNumber = (int) DB::table('raw_material_supplier_compatibility_assessments')
                    ->where('organization_id', $actor->organization_id)
                    ->where('supplier_product_id', $product->id)
                    ->max('assessment_number') + 1;
                $assessmentId = Str::uuid()->toString();
                $assessment = [
                    'id' => $assessmentId,
                    'organization_id' => $actor->organization_id,
                    'supplier_product_id' => $product->id,
                    'supplier_product_revision_id' => $current['supplier_product_revision_id'],
                    'raw_material_id' => $product->raw_material_id,
                    'raw_material_revision_id' => $current['raw_material_revision_id'],
                    'allergen_catalog_version_id' => $current['allergen_catalog_version_id'],
                    'document_version_id' => $evidence->document_version_id,
                    'document_sha256' => $evidence->sha256,
                    'assessment_number' => $assessmentNumber,
                    'outcome' => $payload['outcome'],
                    'decision_reason' => $payload['decision_reason'],
                    'assessed_by' => $actor->id,
                ];
                DB::table('raw_material_supplier_compatibility_assessments')->insert($assessment);

                $operationId = Str::uuid()->toString();
                $auditData = [
                    'assessment_id' => $assessmentId,
                    'supplier_product_id' => $product->id,
                    'supplier_product_revision_id' => $current['supplier_product_revision_id'],
                    'raw_material_id' => $product->raw_material_id,
                    'raw_material_revision_id' => $current['raw_material_revision_id'],
                    'allergen_catalog_version_id' => $current['allergen_catalog_version_id'],
                    'document_version_id' => $evidence->document_version_id,
                    'document_sha256' => $evidence->sha256,
                    'outcome' => $payload['outcome'],
                    'decision_reason' => $payload['decision_reason'],
                ];
                DB::table('raw_materials_commercial_audit_events')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $actor->organization_id,
                    'operation_id' => $operationId,
                    'actor_user_id' => $actor->id,
                    'actor_location_id' => $context->id,
                    'subject_type' => 'SUPPLIER_COMPATIBILITY_ASSESSMENT',
                    'subject_id' => $assessmentId,
                    'event_code' => 'SUPPLIER_COMPATIBILITY_ASSESSED',
                    'outcome' => 'ACCEPTED',
                    'reason_code' => 'USER_PROVIDED',
                    'after_hash' => hash('sha256', json_encode(
                        $this->canonicalize($auditData),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    )),
                    'metadata' => json_encode($auditData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);

                $response = [
                    'data' => [
                        ...$auditData,
                        'assessment_number' => $assessmentNumber,
                        'assessed_by' => $actor->id,
                        'assessed_at' => DB::table('raw_material_supplier_compatibility_assessments')
                            ->where('id', $assessmentId)->value('assessed_at'),
                    ],
                ];
                DB::table('raw_materials_commercial_idempotency')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $actor->organization_id,
                    'actor_user_id' => $actor->id,
                    'operation_code' => 'supplier-compatibility.assess',
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'response_body' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);

                return $response;
            }, attempts: 1),
        );
    }

    private function assessmentContext(User $actor, object $product, ?object $status): ?array
    {
        $revisionId = $status?->supplier_product_revision_id;
        if ($revisionId === null) {
            return null;
        }

        $current = DB::table('supplier_product_revisions')
            ->where('organization_id', $actor->organization_id)
            ->where('supplier_product_id', $product->id)
            ->where('id', $revisionId)
            ->first(['id', 'raw_material_id', 'raw_material_revision_id', 'revision_no']);
        if (! $current) {
            return null;
        }

        $material = DB::table('raw_materials')
            ->where('organization_id', $actor->organization_id)
            ->where('id', $current->raw_material_id)
            ->first(['id', 'approved_revision_id', 'pending_revision_id', 'working_revision_id']);
        $rawRevisionIsCurrent = $material
            && $material->approved_revision_id === $current->raw_material_revision_id
            && $material->pending_revision_id === null
            && $material->working_revision_id === null
            && ! DB::table('raw_material_revisions')
                ->where('organization_id', $actor->organization_id)
                ->where('raw_material_id', $current->raw_material_id)
                ->where('revision_no', '>', DB::table('raw_material_revisions')
                    ->where('id', $current->raw_material_revision_id)
                    ->value('revision_no'))
                ->exists();
        $attestation = $rawRevisionIsCurrent
            ? DB::table('raw_material_revision_allergen_attestations as attestations')
                ->join('raw_material_allergen_catalog_versions as catalog_versions', function ($join): void {
                    $join->on('catalog_versions.id', '=', 'attestations.catalog_version_id')
                        ->where('catalog_versions.status', '=', 'PUBLISHED');
                })
                ->where('attestations.organization_id', $actor->organization_id)
                ->where('attestations.revision_id', $current->raw_material_revision_id)
                ->whereIn('attestations.attestation_status', ['DECLARED', 'NO_KNOWN_ALLERGENS'])
                ->first([
                    'attestations.catalog_version_id',
                    'attestations.attestation_status',
                    'attestations.attested_at',
                    'catalog_versions.version_number',
                ])
            : null;
        $allergens = $attestation
            ? DB::table('raw_material_revision_allergen_declarations as declarations')
                ->join('raw_material_allergen_definitions as definitions', function ($join): void {
                    $join->on('definitions.catalog_version_id', '=', 'declarations.catalog_version_id')
                        ->on('definitions.allergen_code', '=', 'declarations.allergen_code');
                })
                ->where('declarations.organization_id', $actor->organization_id)
                ->where('declarations.revision_id', $current->raw_material_revision_id)
                ->orderBy('declarations.allergen_code')
                ->get(['declarations.allergen_code', 'declarations.exposure_kind', 'definitions.name_pl'])
                ->map(fn (object $allergen): array => [
                    'allergen_code' => $allergen->allergen_code,
                    'name_pl' => $allergen->name_pl,
                    'exposure_kind' => $allergen->exposure_kind,
                ])->all()
            : [];
        $evidence = $rawRevisionIsCurrent && $attestation
            ? DB::table('raw_material_approved_compatibility_document_evidence')
                ->where('organization_id', $actor->organization_id)
                ->where('supplier_product_revision_id', $current->id)
                ->where('raw_material_revision_id', $current->raw_material_revision_id)
                ->orderBy('scanned_at')
                ->get(['document_version_id', 'sha256', 'media_type', 'size_bytes', 'scanned_at'])
                ->map(fn (object $item): array => [
                    'document_version_id' => $item->document_version_id,
                    'sha256' => $item->sha256,
                    'media_type' => $item->media_type,
                    'size_bytes' => (int) $item->size_bytes,
                    'scanned_at' => $item->scanned_at,
                ])->all()
            : [];

        return [
            'supplier_product_revision_id' => $current->id,
            'supplier_product_revision_no' => (int) $current->revision_no,
            'raw_material_revision_id' => $current->raw_material_revision_id,
            'raw_material_revision_current' => (bool) $rawRevisionIsCurrent,
            'allergen_attestation' => $attestation === null ? null : [
                'status' => $attestation->attestation_status,
                'catalog_version_id' => $attestation->catalog_version_id,
                'catalog_version_number' => (int) $attestation->version_number,
                'attested_at' => $attestation->attested_at,
                'allergens' => $allergens,
            ],
            'clean_evidence' => $evidence,
            'business_decision_required' => true,
        ];
    }

    private function currentRevisionContext(User $actor, object $product): array
    {
        if ($product->lifecycle_state !== 'ACTIVE') {
            throw new RawMaterialException(
                'RAW_MATERIAL_COMPATIBILITY_REVISION_UNAVAILABLE',
                __('messages.raw_material_compatibility_revision_unavailable'),
            );
        }

        $supplier = DB::table('organization_suppliers')
            ->where('organization_id', $actor->organization_id)
            ->where('id', $product->supplier_id)
            ->first();
        $material = DB::table('raw_materials')
            ->where('organization_id', $actor->organization_id)
            ->where('id', $product->raw_material_id)
            ->lockForUpdate()
            ->first();
        $productRevision = DB::table('supplier_product_revisions')
            ->where('organization_id', $actor->organization_id)
            ->where('supplier_product_id', $product->id)
            ->orderByDesc('revision_no')
            ->first(['id', 'raw_material_id', 'raw_material_revision_id']);
        $latestRawRevisionId = DB::table('raw_material_revisions')
            ->where('organization_id', $actor->organization_id)
            ->where('raw_material_id', $product->raw_material_id)
            ->orderByDesc('revision_no')
            ->value('id');

        if (! $supplier || $supplier->lifecycle_state !== 'ACTIVE'
            || ! $material || $material->lifecycle_state !== 'ACTIVE'
            || $material->approved_revision_id === null
            || $material->pending_revision_id !== null || $material->working_revision_id !== null
            || $latestRawRevisionId !== $material->approved_revision_id
            || ! $productRevision
            || $productRevision->raw_material_id !== $material->id
            || $productRevision->raw_material_revision_id !== $material->approved_revision_id) {
            throw new RawMaterialException(
                'RAW_MATERIAL_COMPATIBILITY_REVISION_UNAVAILABLE',
                __('messages.raw_material_compatibility_revision_unavailable'),
            );
        }

        $attestation = DB::table('raw_material_revision_allergen_attestations as attestations')
            ->join('raw_material_allergen_catalog_versions as catalog_versions', 'catalog_versions.id', '=', 'attestations.catalog_version_id')
            ->where('attestations.organization_id', $actor->organization_id)
            ->where('attestations.revision_id', $material->approved_revision_id)
            ->where('catalog_versions.status', 'PUBLISHED')
            ->whereIn('attestations.attestation_status', ['DECLARED', 'NO_KNOWN_ALLERGENS'])
            ->first(['attestations.catalog_version_id']);
        if (! $attestation) {
            throw new RawMaterialException(
                'RAW_MATERIAL_COMPATIBILITY_ATTESTATION_UNAVAILABLE',
                __('messages.raw_material_compatibility_attestation_unavailable'),
            );
        }

        return [
            'supplier_product_revision_id' => $productRevision->id,
            'raw_material_revision_id' => $material->approved_revision_id,
            'allergen_catalog_version_id' => $attestation->catalog_version_id,
        ];
    }

    private function product(User $actor, string $productId, bool $lock = false): object
    {
        $query = DB::table('supplier_products')
            ->where('organization_id', $actor->organization_id)
            ->where('id', $productId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $product = $query->first();
        if (! $product) {
            throw new RawMaterialException(
                'RAW_MATERIAL_RESOURCE_NOT_FOUND',
                __('messages.resource_not_found'),
                404,
            );
        }

        return $product;
    }

    private function assertMaterialSharedForConsumer(User $actor, Location $context, string $materialId): void
    {
        if (! $this->sharePolicy->isPrimaryContext($context)) {
            $this->sharePolicy->assertCanConsume($actor, $context, $materialId);
        }
    }

    private function validateDecision(array $input, string $idempotencyKey): void
    {
        if (! in_array($input['outcome'] ?? null, ['COMPATIBLE', 'INCOMPATIBLE'], true)
            || ! is_string($input['decision_reason'] ?? null)
            || mb_strlen(trim($input['decision_reason'])) < 20
            || mb_strlen(trim($input['decision_reason'])) > 2000
            || ($input['allergen_declaration_reviewed'] ?? null) !== true
            || ! Str::isUuid($input['document_version_id'] ?? '')) {
            throw ValidationException::withMessages([
                'outcome' => [__('messages.raw_material_compatibility_decision_invalid')],
            ]);
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{8,120}$/D', $idempotencyKey)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => [__('messages.raw_material_idempotency_key_required')],
            ]);
        }
    }

    private function canonicalize(array $value): array
    {
        ksort($value);

        return $value;
    }
}
