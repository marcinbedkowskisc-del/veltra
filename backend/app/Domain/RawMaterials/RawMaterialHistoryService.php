<?php

namespace App\Domain\RawMaterials;

use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RawMaterialHistoryService
{
    private const MATERIAL_EVENTS = ['CREATED', 'REVISION_CREATED', 'SUBMITTED', 'RETURNED', 'APPROVED', 'BLOCKED', 'UNBLOCKED', 'ARCHIVED', 'RESTORE_SUBMITTED'];

    public function __construct(
        private RawMaterialAuthorization $authorization,
        private RawMaterialLocationSharePolicy $sharePolicy,
    ) {}

    public function history(User $actor, Location $context, string $materialId, array $filters): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::AuditView);
        if (! $this->sharePolicy->isPrimaryContext($context)) {
            $this->sharePolicy->assertCanConsume($actor, $context, $materialId);
        }
        $material = DB::table('raw_materials')->where('organization_id', $actor->organization_id)
            ->where('id', $materialId)->whereNull('deleted_at')->first();
        if (! $material) {
            throw new RawMaterialException('RAW_MATERIAL_NOT_FOUND', __('messages.resource_not_found'), 404);
        }
        $page = $this->visibleEvents($actor, $context, $material)
            ->orderByDesc('events.occurred_at')->orderByDesc('events.id')
            ->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1);

        return [
            'data' => $page->getCollection()->map(fn (object $event): array => $this->present($event, true))->all(),
            'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'last_page' => $page->lastPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
        ];
    }

    public function metadata(User $actor, Location $context, object $material): array
    {
        $canAudit = $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::AuditView);
        $event = $this->visibleEvents($actor, $context, $material)
            ->where('events.event_code', '<>', 'REFERENCE_PRICE_UNCHANGED')
            ->orderByDesc('events.occurred_at')->orderByDesc('events.id')->first();

        return [
            'last_update' => $event === null ? null : [
                'occurred_at' => $event->occurred_at,
                'actor' => $canAudit ? $this->present($event, true)['actor'] : null,
            ],
            'history_url' => $canAudit ? '/api/v1/raw-materials/'.$material->id.'/history' : null,
        ];
    }

    private function visibleEvents(User $actor, Location $context, object $material): Builder
    {
        $canAudit = $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::AuditView);
        $primary = $this->sharePolicy->isPrimaryContext($context);
        $canSeeWorking = $primary && ($canAudit || $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::Edit));
        $events = DB::table('raw_material_audit_events')
            ->where('organization_id', $actor->organization_id)
            ->where('outcome', 'ACCEPTED')
            ->where(function ($query) use ($material, $canAudit, $primary): void {
                $query->where(function ($core) use ($material): void {
                    $core->where('subject_type', 'RAW_MATERIAL')->where('subject_id', $material->id)
                        ->whereIn('event_code', self::MATERIAL_EVENTS);
                });
                if ($primary && $canAudit) {
                    $query->orWhere(fn ($shares) => $shares->where('subject_type', 'RAW_MATERIAL_SHARE')
                        ->whereRaw("metadata->>'raw_material_id' = ?", [$material->id])
                        ->whereIn('event_code', ['LOCATION_SHARE_CREATED', 'LOCATION_SHARE_REVOKED']));
                }
            });
        if (! $canSeeWorking) {
            $events->where('subject_type', 'RAW_MATERIAL')->where('event_code', 'APPROVED')
                ->whereRaw("metadata->>'revision_id' = ?", [$material->approved_revision_id]);
        }
        $columns = ['id', 'actor_user_id', 'event_code', 'occurred_at'];
        $events->select($columns);
        if ($primary && $canAudit && $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::ReferencePriceView)) {
            $prices = DB::table('raw_materials_commercial_audit_events')
                ->where('organization_id', $actor->organization_id)->where('outcome', 'ACCEPTED')
                ->where('subject_type', 'RAW_MATERIAL_REFERENCE_PRICE')
                ->whereRaw("metadata->'record'->>'raw_material_id' = ?", [$material->id])
                ->whereIn('event_code', ['REFERENCE_PRICE_SET', 'REFERENCE_PRICE_WITHDRAWN', 'REFERENCE_PRICE_UNCHANGED'])
                ->select($columns);
            $events->unionAll($prices);
            $imports = DB::table('raw_material_reference_price_events as prices')
                ->where('prices.organization_id', $actor->organization_id)
                ->where('prices.raw_material_id', $material->id)
                ->whereExists(fn ($rows) => $rows->selectRaw('1')->from('raw_material_import_rows as rows')
                    ->whereColumn('rows.organization_id', 'prices.organization_id')
                    ->whereColumn('rows.raw_material_id', 'prices.raw_material_id')
                    ->whereColumn('rows.created_reference_price_event_id', 'prices.id'))
                ->selectRaw("prices.id, prices.recorded_by AS actor_user_id, 'REFERENCE_PRICE_IMPORTED' AS event_code, prices.recorded_at AS occurred_at");
            $events->unionAll($imports);
        }
        if ($primary && $canAudit) {
            $documents = DB::table('raw_material_document_lifecycle_events as documents')
                ->where('documents.organization_id', $actor->organization_id)
                ->where('documents.event_code', 'VERSION_LINKED')
                ->whereExists(fn ($links) => $links->selectRaw('1')
                    ->from('raw_material_document_version_links as links')
                    ->join('raw_material_revisions as revisions', 'revisions.id', '=', 'links.raw_material_revision_id')
                    ->whereColumn('links.document_version_id', 'documents.document_version_id')
                    ->whereColumn('links.organization_id', 'documents.organization_id')
                    ->whereColumn('revisions.organization_id', 'links.organization_id')
                    ->where('revisions.raw_material_id', $material->id)
                    ->whereNull('links.supplier_product_revision_id'))
                ->select($columns);
            $events->unionAll($documents);
        }

        return DB::query()->fromSub($events, 'events')
            ->leftJoin('users', function ($join) use ($actor): void {
                $join->on('users.id', '=', 'events.actor_user_id')->where('users.organization_id', $actor->organization_id);
            })->select('events.*', 'users.first_name', 'users.last_name');
    }

    private function present(object $event, bool $includeActor): array
    {
        return [
            'id' => $event->id, 'event_code' => $event->event_code, 'occurred_at' => $event->occurred_at,
            'actor' => $includeActor ? [
                'id' => $event->actor_user_id, 'first_name' => $event->first_name, 'last_name' => $event->last_name,
            ] : null,
        ];
    }
}
