<?php

namespace App\Domain\Sku;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class SkuAllocationService
{
    public function __construct(private readonly AuthorizationMutationContext $contexts) {}

    public function allocateNext(User $actor, SkuPrefix $prefix, string $subjectId): string
    {
        $this->assertTransactionAndSubject($subjectId);

        return $this->contexts->runForSubject(
            AuthorizationMutationScope::skuAllocate(),
            $actor->id,
            $subjectId,
            function () use ($actor, $prefix, $subjectId): string {
                $this->lockOrganization($actor);
                $counter = $this->lockCounter($actor, $prefix);
                $next = (int) $counter->last_value + 1;
                $sku = $prefix->format($next);

                DB::table('organization_sku_counters')
                    ->where('organization_id', $actor->organization_id)
                    ->where('prefix', $prefix->value)
                    ->update(['last_value' => $next, 'updated_at' => now()]);

                $this->insertAssignment($actor, $prefix, $sku, $next, $subjectId, 'GENERATED');

                return $sku;
            },
        );
    }

    public function reserveImported(User $actor, SkuPrefix $prefix, string $sku, string $subjectId): string
    {
        $this->assertTransactionAndSubject($subjectId);
        $sequence = $prefix->parse($sku);
        if ($sequence === null) {
            throw new \InvalidArgumentException('Importowany SKU nie odpowiada zatwierdzonemu formatowi kategorii.');
        }

        return $this->contexts->runForSubject(
            AuthorizationMutationScope::skuAllocate(),
            $actor->id,
            $subjectId,
            function () use ($actor, $prefix, $sku, $subjectId, $sequence): string {
                $this->lockOrganization($actor);
                $counter = $this->lockCounter($actor, $prefix);
                if (DB::table('organization_sku_assignments')
                    ->where('organization_id', $actor->organization_id)
                    ->where('sku', $sku)
                    ->exists()) {
                    throw new \DomainException('SKU jest już użyty albo zarezerwowany w tej organizacji.');
                }

                if ($sequence > (int) $counter->last_value) {
                    DB::table('organization_sku_counters')
                        ->where('organization_id', $actor->organization_id)
                        ->where('prefix', $prefix->value)
                        ->update(['last_value' => $sequence, 'updated_at' => now()]);
                }

                $this->insertAssignment($actor, $prefix, $sku, $sequence, $subjectId, 'IMPORTED');

                return $sku;
            },
        );
    }

    private function lockOrganization(User $actor): void
    {
        DB::table('organizations')
            ->where('id', $actor->organization_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockCounter(User $actor, SkuPrefix $prefix): object
    {
        $counter = DB::table('organization_sku_counters')
            ->where('organization_id', $actor->organization_id)
            ->where('prefix', $prefix->value)
            ->lockForUpdate()
            ->first();

        if ($counter !== null) {
            return $counter;
        }

        DB::table('organization_sku_counters')->insertOrIgnore([
            'organization_id' => $actor->organization_id,
            'prefix' => $prefix->value,
            'last_value' => 0,
            'updated_at' => now(),
        ]);

        return DB::table('organization_sku_counters')
            ->where('organization_id', $actor->organization_id)
            ->where('prefix', $prefix->value)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function insertAssignment(
        User $actor,
        SkuPrefix $prefix,
        string $sku,
        int $sequence,
        string $subjectId,
        string $source,
    ): void {
        DB::table('organization_sku_assignments')->insert([
            'organization_id' => $actor->organization_id,
            'sku' => $sku,
            'prefix' => $prefix->value,
            'sequence_number' => $sequence,
            'entity_type' => $prefix->value,
            'entity_id' => $subjectId,
            'allocation_source' => $source,
            'assigned_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function assertTransactionAndSubject(string $subjectId): void
    {
        if (DB::transactionLevel() < 1 || ! Str::isUuid($subjectId)) {
            throw new LogicException('Alokacja SKU wymaga transakcji biznesowej i UUID podmiotu.');
        }
    }
}
