<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\BossRawCatalogIntent;
use App\Domain\Authorization\BossVisibilityCategory;
use App\Domain\Authorization\BusinessDataProvenance;
use App\Domain\Authorization\BusinessVisibility;
use App\Domain\Authorization\BusinessVisibilitySource;
use App\Domain\Authorization\BusinessVisibilitySurface;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class BossRawMaterialCatalogService
{
    public function __construct(
        private readonly BusinessVisibility $visibility,
    ) {}

    /**
     * @param  array{
     *     search?: string,
     *     category_id?: string,
     *     unit?: string,
     *     sort?: string,
     *     direction?: string,
     *     page?: int|string,
     *     per_page?: int|string
     * }  $filters
     * @return array{
     *     data: list<array{
     *         id: string,
     *         code: string,
     *         name: string,
     *         category: array{id: string, name: string},
     *         base_unit_code: string
     *     }>,
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function listing(
        User $requestActor,
        Location $requestContext,
        ?string $requestMembershipId,
        ?int $requestIdentityVersion,
        array $filters,
    ): array {
        return $this->withAuthorizedRead(
            $requestActor,
            $requestContext,
            $requestMembershipId,
            $requestIdentityVersion,
            BossRawCatalogIntent::List,
            function (User $actor, Location $context, string $organizationId) use ($filters): array {
                $query = $this->approvedMaterials($organizationId);
                $this->filter($query, $filters);

                $page = (int) ($filters['page'] ?? 1);
                $perPage = (int) ($filters['per_page'] ?? 25);
                $total = (clone $query)->count();
                $offset = $this->offset($page, $perPage);
                $rows = (clone $query)
                    ->orderBy($this->sortColumn($filters['sort'] ?? 'name'), $filters['direction'] ?? 'asc')
                    ->orderBy('materials.id')
                    ->offset($offset)
                    ->limit($perPage)
                    ->get($this->projectionColumns());

                return [
                    'data' => $rows->map(
                        static fn (object $row): array => BossRawMaterialCatalogItem::fromDatabaseRow($row)->toArray(),
                    )->all(),
                    'meta' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'last_page' => max(1, (int) ceil($total / $perPage)),
                    ],
                ];
            },
        );
    }

    /**
     * @return array{
     *     id: string,
     *     code: string,
     *     name: string,
     *     category: array{id: string, name: string},
     *     base_unit_code: string
     * }
     */
    public function detail(
        User $requestActor,
        Location $requestContext,
        ?string $requestMembershipId,
        ?int $requestIdentityVersion,
        string $materialId,
    ): array {
        return $this->withAuthorizedRead(
            $requestActor,
            $requestContext,
            $requestMembershipId,
            $requestIdentityVersion,
            BossRawCatalogIntent::Detail,
            function (User $actor, Location $context, string $organizationId) use ($materialId): array {
                $row = $this->approvedMaterials($organizationId)
                    ->where('materials.id', $materialId)
                    ->first($this->projectionColumns());
                if (! $row) {
                    throw new HttpException(404, __('messages.resource_not_found'));
                }

                return BossRawMaterialCatalogItem::fromDatabaseRow($row)->toArray();
            },
        );
    }

    /**
     * @template T
     *
     * @param  Closure(User, Location, string): T  $read
     * @return T
     */
    public function withAuthorizedRead(
        User $requestActor,
        Location $requestContext,
        ?string $requestMembershipId,
        ?int $requestIdentityVersion,
        BossRawCatalogIntent $intent,
        Closure $read,
    ): mixed {
        try {
            return DB::transaction(function () use (
                $requestActor,
                $requestContext,
                $requestMembershipId,
                $requestIdentityVersion,
                $intent,
                $read,
            ): mixed {
                $organization = Organization::query()
                    ->select(['id', 'is_active'])
                    ->whereKey($requestActor->organization_id)
                    ->sharedLock()
                    ->first();

                if (! $organization || ! $organization->is_active) {
                    throw new HttpException(401, __('messages.unauthenticated'));
                }

                $actor = User::query()->with('organization')->find($requestActor->id);
                if (! $actor
                    || ! $actor->is_active
                    || $actor->email_verified_at === null
                    || $actor->organization_id !== $organization->id
                    || $actor->auth_version !== $requestIdentityVersion) {
                    throw new HttpException(401, __('messages.unauthenticated'));
                }

                if (! is_string($requestMembershipId) || $requestMembershipId === '') {
                    throw new HttpException(403, __('messages.unauthorized'));
                }

                $membership = DB::table('location_memberships as memberships')
                    ->join('locations', 'locations.id', '=', 'memberships.location_id')
                    ->where('memberships.id', $requestMembershipId)
                    ->where('memberships.user_id', $actor->id)
                    ->where('memberships.organization_id', $organization->id)
                    ->where('memberships.location_id', $requestContext->id)
                    ->where('memberships.is_active', true)
                    ->where('locations.organization_id', $organization->id)
                    ->where('locations.is_active', true)
                    ->first(['locations.id']);
                if (! $membership) {
                    throw new HttpException(403, __('messages.unauthorized'));
                }

                $context = Location::query()
                    ->whereKey($membership->id)
                    ->where('organization_id', $organization->id)
                    ->where('is_active', true)
                    ->first();
                if (! $context) {
                    throw new HttpException(403, __('messages.unauthorized'));
                }

                $surface = new BusinessVisibilitySurface(
                    key: 'boss.raw_catalog.'.$intent->value,
                    source: BusinessVisibilitySource::Boss,
                    operation: $intent->value,
                    permission: null,
                    categories: [BossVisibilityCategory::RawSuppliers],
                    provenance: BusinessDataProvenance::organization(),
                    intent: $intent,
                );
                $decision = $this->visibility->decide($actor, $context, $surface);
                if (! $decision->allowed) {
                    if ($decision->reason === 'BOSS_CAPABILITY_UNAVAILABLE') {
                        throw new HttpException(503, __('messages.boss_raw_catalog_unavailable'));
                    }
                    if ($decision->reason === 'BOSS_ACCESS_NOT_AUTHORIZED') {
                        throw new HttpException(403, __('messages.unauthorized'));
                    }

                    throw new HttpException(403, __('messages.unauthorized'));
                }

                return $read($actor, $context, $organization->id);
            });
        } catch (QueryException $exception) {
            Log::warning('BOSS raw catalog read dependency failed.', [
                'operation' => $intent->value,
                'sqlstate' => $exception->errorInfo[0] ?? null,
            ]);

            throw new HttpException(
                503,
                __('messages.boss_raw_catalog_unavailable'),
                $exception,
            );
        }
    }

    private function approvedMaterials(string $organizationId): Builder
    {
        return DB::table('raw_materials as materials')
            ->join('raw_material_revisions as revisions', function ($join): void {
                $join->on('revisions.id', '=', 'materials.approved_revision_id')
                    ->on('revisions.raw_material_id', '=', 'materials.id')
                    ->on('revisions.organization_id', '=', 'materials.organization_id');
            })
            ->join('raw_material_categories as categories', function ($join): void {
                $join->on('categories.id', '=', 'revisions.category_id')
                    ->on('categories.organization_id', '=', 'materials.organization_id');
            })
            ->leftJoin('raw_material_approved_supplier_snapshots as supplier_snapshots', function ($join): void {
                $join->on('supplier_snapshots.organization_id', '=', 'materials.organization_id')
                    ->on('supplier_snapshots.raw_material_id', '=', 'materials.id')
                    ->on('supplier_snapshots.revision_id', '=', 'revisions.id');
            })
            ->where('materials.organization_id', $organizationId)
            ->where('materials.lifecycle_state', 'ACTIVE')
            ->whereNull('materials.deleted_at')
            ->whereNotNull('materials.approved_revision_id')
            ->whereExists(function (Builder $approval): void {
                $approval->selectRaw('1')
                    ->from('raw_material_revision_approval_events as approvals')
                    ->whereColumn('approvals.organization_id', 'materials.organization_id')
                    ->whereColumn('approvals.revision_id', 'revisions.id')
                    ->where('approvals.outcome', 'APPROVED')
                    ->whereColumn('approvals.content_hash', 'revisions.content_hash');
            });
    }

    /**
     * @return list<string>
     */
    private function projectionColumns(): array
    {
        return [
            'materials.id as id',
            'materials.code as code',
            'revisions.name as name',
            'categories.id as category_id',
            'categories.name as category_name',
            'revisions.base_unit_code as base_unit_code',
            'supplier_snapshots.state as supplier_snapshot_state',
            DB::raw(<<<'SQL'
                CASE
                    WHEN supplier_snapshots.state = 'APPROVED_EMPTY' THEN '[]'::text
                    WHEN supplier_snapshots.state = 'APPROVED_NONEMPTY' THEN
                        COALESCE((
                            SELECT json_agg(DISTINCT coalesce(
                                nullif(btrim(snapshot_suppliers.display_name), ''),
                                snapshot_suppliers.legal_name
                            ) ORDER BY coalesce(
                                nullif(btrim(snapshot_suppliers.display_name), ''),
                                snapshot_suppliers.legal_name
                            ))::text
                            FROM raw_material_approved_supplier_snapshot_items snapshot_items
                            JOIN organization_suppliers snapshot_suppliers
                              ON snapshot_suppliers.id = snapshot_items.supplier_id
                             AND snapshot_suppliers.organization_id = snapshot_items.organization_id
                            WHERE snapshot_items.snapshot_id = supplier_snapshots.id
                              AND snapshot_items.organization_id = supplier_snapshots.organization_id
                              AND snapshot_items.revision_id = supplier_snapshots.revision_id
                        ), '[]')
                    ELSE NULL
                END as supplier_names_json
                SQL),
        ];
    }

    /**
     * @param  array{
     *     search?: string,
     *     category_id?: string,
     *     unit?: string,
     *     sort?: string,
     *     direction?: string
     * }  $filters
     */
    private function filter(Builder $query, array $filters): void
    {
        if (isset($filters['category_id'])) {
            $query->where('categories.id', $filters['category_id']);
        }
        if (isset($filters['unit'])) {
            $query->where('revisions.base_unit_code', $filters['unit']);
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $literal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']);
            $pattern = '%'.$literal.'%';
            $query->where(function (Builder $search) use ($pattern): void {
                $search->whereRaw("revisions.name ILIKE ? ESCAPE E'\\\\'", [$pattern])
                    ->orWhereRaw("materials.code ILIKE ? ESCAPE E'\\\\'", [$pattern]);
            });
        }
    }

    private function sortColumn(string $sort): string
    {
        return match ($sort) {
            'name' => 'revisions.name',
            'code' => 'materials.code',
            'category' => 'categories.name',
            'unit' => 'revisions.base_unit_code',
            default => throw new \LogicException('Unvalidated BOSS raw catalog sort value.'),
        };
    }

    private function offset(int $page, int $perPage): int
    {
        if ($page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            return PHP_INT_MAX;
        }

        return ($page - 1) * $perPage;
    }
}
