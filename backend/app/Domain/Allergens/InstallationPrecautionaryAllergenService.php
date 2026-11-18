<?php

namespace App\Domain\Allergens;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InstallationPrecautionaryAllergenService
{
    public function __construct(
        private readonly BusinessAuthorization $authorization,
        private readonly AuthorizationMutationContext $contexts,
    ) {}

    public function listForAdministration(User $actor, Location $context): array
    {
        $this->assertAdministrator($actor, $context);

        return $this->allergens();
    }

    public function allergenIds(): array
    {
        return DB::table('installation_precautionary_allergens')
            ->orderBy('allergen_id')
            ->pluck('allergen_id')
            ->all();
    }

    public function add(User $actor, Location $context, string $allergenId): array
    {
        $this->assertAdministrator($actor, $context);

        return $this->contexts->run(
            AuthorizationMutationScope::allergenDictionaryManage(),
            $actor->id,
            fn (): array => DB::transaction(function () use ($actor, $context, $allergenId): array {
                $this->assertAdministrator($actor, $context);
                $allergen = DB::table('international_allergen_canonical_definitions')
                    ->where('id', $allergenId)
                    ->lockForUpdate()
                    ->first(['id', 'canonical_pl', 'record_status']);
                if (! $allergen || $allergen->record_status !== 'ACTIVE') {
                    throw ValidationException::withMessages([
                        'allergen_id' => ['Wybierz alergen z dostępnej listy.'],
                    ]);
                }

                if (DB::table('installation_precautionary_allergens')->where('allergen_id', $allergenId)->exists()) {
                    throw new HttpException(409, 'Ten alergen jest już na liście MOŻE ZAWIERAĆ.');
                }

                DB::table('installation_precautionary_allergens')->insert([
                    'allergen_id' => $allergenId,
                    'added_by' => $actor->id,
                    'created_at' => now(),
                ]);

                $after = ['allergen_id' => $allergen->id, 'canonical_pl' => $allergen->canonical_pl];
                $this->audit($actor, $context, 'INSTALLATION_PRECAUTIONARY_ALLERGEN_ADDED', null, $after);

                return $after;
            }),
        );
    }

    public function remove(User $actor, Location $context, string $allergenId): array
    {
        $this->assertAdministrator($actor, $context);

        return $this->contexts->run(
            AuthorizationMutationScope::allergenDictionaryManage(),
            $actor->id,
            fn (): array => DB::transaction(function () use ($actor, $context, $allergenId): array {
                $this->assertAdministrator($actor, $context);
                $allergen = DB::table('international_allergen_canonical_definitions')
                    ->where('id', $allergenId)
                    ->lockForUpdate()
                    ->first(['id', 'canonical_pl']);
                if (! $allergen) {
                    throw new HttpException(404, 'Nie znaleziono alergenu.');
                }

                $listed = DB::table('installation_precautionary_allergens')
                    ->where('allergen_id', $allergenId)
                    ->first();
                if (! $listed) {
                    throw new HttpException(404, 'Tego alergenu nie ma na liście MOŻE ZAWIERAĆ.');
                }

                DB::table('installation_precautionary_allergens')
                    ->where('allergen_id', $allergenId)
                    ->delete();

                $before = ['allergen_id' => $allergen->id, 'canonical_pl' => $allergen->canonical_pl];
                $this->audit($actor, $context, 'INSTALLATION_PRECAUTIONARY_ALLERGEN_REMOVED', $before, null);

                return $before;
            }),
        );
    }

    private function allergens(): array
    {
        return DB::table('installation_precautionary_allergens as configured')
            ->join(
                'international_allergen_canonical_definitions as allergens',
                'allergens.id',
                '=',
                'configured.allergen_id',
            )
            ->orderBy('allergens.canonical_pl')
            ->orderBy('allergens.id')
            ->get(['allergens.id as allergen_id', 'allergens.canonical_pl'])
            ->map(fn (object $allergen): array => [
                'allergen_id' => $allergen->allergen_id,
                'canonical_pl' => $allergen->canonical_pl,
            ])->all();
    }

    private function audit(
        User $actor,
        Location $context,
        string $eventCode,
        ?array $before,
        ?array $after,
    ): void {
        $beforeJson = $before === null
            ? null
            : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $afterJson = $after === null
            ? null
            : json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        DB::table('raw_material_audit_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'operation_id' => Str::uuid()->toString(),
            'actor_user_id' => $actor->id,
            'actor_location_id' => $context->id,
            'subject_type' => 'INSTALLATION_PRECAUTIONARY_ALLERGEN',
            'subject_id' => null,
            'event_code' => $eventCode,
            'outcome' => 'ACCEPTED',
            'before_hash' => $beforeJson === null ? null : hash('sha256', $beforeJson),
            'after_hash' => $afterJson === null ? null : hash('sha256', $afterJson),
            'metadata' => json_encode([
                'scope' => 'INSTALLATION',
                'before' => $before,
                'after' => $after,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function assertAdministrator(User $actor, Location $context): void
    {
        if (! $this->authorization->eligible($actor, $context)
            || ! $this->authorization->hasRole($actor, BusinessRole::Administrator)) {
            throw new HttpException(403, __('messages.unauthorized'));
        }
    }
}
