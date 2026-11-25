<?php

namespace Tests\Feature;

use App\Domain\Authorization\BossLocationRestrictions;
use App\Domain\Authorization\BossRawCatalogIntent;
use App\Domain\Authorization\BossVisibilityCategory;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessDataProvenance;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\BusinessVisibility;
use App\Domain\Authorization\BusinessVisibilitySource;
use App\Domain\Authorization\BusinessVisibilitySurface;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityAccess;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityService;
use App\Domain\RawMaterials\BossRawMaterialCatalogService;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialCatalogValues;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\AuthorizationTestCase;
use Tests\Support\OrganizationCapabilityCatalogFixtures;

class BossRawMaterialCatalogAuthorizationTest extends AuthorizationTestCase
{
    private Location $context;

    private User $boss;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeRestaurant();
        $this->context = $this->primaryCentralKitchen();
        $this->boss = $this->provisionedUser('boss-raw-catalog-auth@example.test', BusinessRole::Boss);
        $this->administrator = $this->provisionedUser('admin-raw-catalog-auth@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->boss, $this->context, $this->boss->id);
        app(MembershipService::class)->assign($this->administrator, $this->context, $this->administrator->id);
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        app(OrganizationCapabilityService::class)->configure(
            $this->administrator,
            $this->context,
            CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
            Str::uuid()->toString(),
            [
                'enabled' => true,
                'expected_state_version' => 0,
                'current_password' => self::PASSWORD,
            ],
        );
    }

    public function test_only_the_two_typed_boss_raw_catalog_intents_are_authorized(): void
    {
        $visibility = app(BusinessVisibility::class);

        foreach ([BossRawCatalogIntent::List, BossRawCatalogIntent::Detail] as $intent) {
            $this->assertTrue($visibility->decide(
                $this->boss,
                $this->context,
                $this->surface($intent),
            )->allowed);
        }

        $this->expectException(InvalidArgumentException::class);
        new BusinessVisibilitySurface(
            'boss.raw_catalog.list',
            BusinessVisibilitySource::Stage1d,
            'list',
            null,
            [BossVisibilityCategory::RawSuppliers],
            BusinessDataProvenance::organization(),
            intent: BossRawCatalogIntent::List,
        );
    }

    public function test_revoked_boss_role_denies_catalog_access(): void
    {
        $bossRole = RoleAssignment::effective()
            ->where('user_id', $this->boss->id)
            ->where('role', BusinessRole::Boss)
            ->whereNull('location_id')
            ->firstOrFail();
        app(RoleAdministration::class)->replace(
            $this->administrator,
            $this->context,
            $this->boss,
            BusinessRole::Administrator,
            [],
            self::PASSWORD,
        );
        $this->assertNotNull($bossRole->fresh()->revoked_at);

        $decision = app(BusinessVisibility::class)->decide(
            $this->boss,
            $this->context,
            $this->surface(BossRawCatalogIntent::List),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('BOSS_ACCESS_NOT_AUTHORIZED', $decision->reason);
        $this->assertSame(BusinessVisibilitySource::Boss, $decision->source);
    }

    public function test_raw_only_grant_does_not_authorize_catalog_list_or_detail(): void
    {
        $rawOnly = $this->provisionedUser('raw-only-boss-catalog@example.test', BusinessRole::CentralKitchenChef, $this->context);
        $this->grantRawView($rawOnly);

        $this->assertTrue(app(RawMaterialAuthorization::class)
            ->hasEffectiveGrant($rawOnly, RawMaterialPermission::View));
        $this->assertFalse(app(BusinessAuthorization::class)
            ->hasOrganizationRole($rawOnly, BusinessRole::Boss));
        $this->assertCatalogDeniedForBothIntents($rawOnly, $this->context);
    }

    public function test_boss_cannot_use_an_independent_raw_write_grant(): void
    {
        $this->grantRawView($this->boss, [RawMaterialPermission::View, RawMaterialPermission::Create]);
        $this->app->bind(ModuleAvailability::class, fn () => new class implements ModuleAvailability
        {
            public function enabled(Location $location, string $module): bool
            {
                return true;
            }
        });
        $authorization = app(RawMaterialAuthorization::class);
        $this->assertTrue($authorization->hasEffectiveGrant($this->boss, RawMaterialPermission::View));
        $this->assertFalse($authorization->hasEffectiveGrant($this->boss, RawMaterialPermission::Create));
        $this->expectException(AuthorizationException::class);
        $authorization->assertAllowed($this->boss, $this->context, RawMaterialPermission::Create);
    }

    public function test_role_replacement_permanently_revokes_raw_write_grants(): void
    {
        $user = $this->provisionedUser('raw-role-replacement@example.test', BusinessRole::CentralKitchenChef, $this->context);
        $this->grantRawView($user, [RawMaterialPermission::View, RawMaterialPermission::Create]);
        $authorization = app(RawMaterialAuthorization::class);
        $this->assertTrue($authorization->hasEffectiveGrant($user, RawMaterialPermission::Create));
        $roles = app(RoleAdministration::class);
        $roles->replace($this->administrator, $this->context, $user, BusinessRole::Boss, []);
        $this->assertFalse($authorization->hasEffectiveGrant($user, RawMaterialPermission::Create));
        $this->assertTrue($authorization->hasEffectiveGrant($user, RawMaterialPermission::View));
        $roles->replace($this->administrator, $this->context, $user, BusinessRole::CentralKitchenChef, [$this->context->id]);
        $this->assertFalse($authorization->hasEffectiveGrant($user, RawMaterialPermission::Create));
        $this->assertDatabaseHas('raw_material_audit_events', [
            'event_code' => 'PERMISSION_REVOKED', 'reason_code' => 'ROLE_REPLACED_BOSS',
        ]);
    }

    public function test_new_raw_write_grants_cannot_be_delegated_to_boss(): void
    {
        $steward = $this->provisionedUser('raw-delegation-steward@example.test', BusinessRole::CentralKitchenChef, $this->context);
        $this->grantRawView($steward, [RawMaterialPermission::PermissionDelegate, RawMaterialPermission::Create]);
        $this->app->bind(ModuleAvailability::class, fn () => new class implements ModuleAvailability
        {
            public function enabled(Location $location, string $module): bool
            {
                return true;
            }
        });
        $this->expectException(AuthorizationException::class);
        app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $steward, $this->context, $this->boss, RawMaterialPermission::Create, 'Niedozwolony zapis BOSS',
        );
    }

    #[DataProvider('rawFinancialConfigurations')]
    public function test_boss_raw_financial_projection_obeys_individual_restrictions(bool $foodcost, bool $prices): void
    {
        $excluded = [];
        if (! $foodcost) {
            $excluded[] = BossVisibilityCategory::Foodcost->value;
        }
        if (! $prices) {
            $excluded[] = BossVisibilityCategory::PurchaseFinancials->value;
        }
        $this->grantRawView($this->boss, [RawMaterialPermission::ReferencePriceView, RawMaterialPermission::PurchasingCostsView]);
        app(BossLocationRestrictions::class)->replace(
            $this->administrator, $this->context, $this->boss->id, 0, [], $excluded, [],
        );
        $authorization = app(RawMaterialAuthorization::class);
        $values = app(RawMaterialCatalogValues::class);
        $this->assertTrue($authorization->canRead($this->boss, $this->context, RawMaterialPermission::View));
        $this->assertSame($prices, $authorization->canRead($this->boss, $this->context, RawMaterialPermission::PurchasingCostsView));
        $this->assertSame($prices, $values->canReadPrice($this->boss, $this->context));
        $this->assertSame($foodcost, $values->canReadCost($this->boss, $this->context));
        if (! $foodcost || ! $prices) {
            $this->expectException(AuthorizationException::class);
        }
        $authorization->assertDocumentReadAllowed($this->boss, $this->context);
    }

    public static function rawFinancialConfigurations(): array
    {
        return [
            'both enabled' => [true, true],
            'both disabled' => [false, false],
            'only foodcost' => [true, false],
            'only purchase prices' => [false, true],
        ];
    }

    public function test_boss_raw_checks_central_kitchen_restrictions_from_restaurant_context(): void
    {
        $restaurant = $this->activeRestaurant('RAW-RESTAURANT');
        app(MembershipService::class)->assign($this->boss, $restaurant);
        app(BossLocationRestrictions::class)->replace($this->administrator, $this->context, $this->boss->id, 0, [], [], [[
            'category' => BossVisibilityCategory::PurchaseFinancials->value,
            'location_id' => $this->context->id,
        ]]);
        $values = app(RawMaterialCatalogValues::class);
        $this->assertFalse($values->canReadPrice($this->boss, $restaurant));
        $this->assertTrue($values->canReadCost($this->boss, $restaurant));
        $this->assertTrue(app(RawMaterialAuthorization::class)->canRead($this->boss, $restaurant, RawMaterialPermission::View));
    }

    public function test_stage1d_only_authority_does_not_authorize_catalog_list_or_detail(): void
    {
        $restaurant = $this->activeRestaurant('R-STAGE1D');
        $stage1dOnly = $this->provisionedUser('stage1d-only-boss-catalog@example.test', BusinessRole::Employee, $restaurant);

        $this->assertTrue(app(BusinessAuthorization::class)->allows(
            $stage1dOnly,
            $restaurant,
            $restaurant,
            Permission::LocationView,
        ));
        $this->assertFalse(app(BusinessAuthorization::class)
            ->hasOrganizationRole($stage1dOnly, BusinessRole::Boss));
        $this->assertCatalogDeniedForBothIntents($stage1dOnly, $restaurant);
    }

    public function test_read_locks_organization_before_revalidating_the_request_membership(): void
    {
        $membershipId = $this->membershipId($this->boss);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $result = app(BossRawMaterialCatalogService::class)->withAuthorizedRead(
                $this->boss,
                $this->context,
                $membershipId,
                $this->boss->auth_version,
                BossRawCatalogIntent::List,
                fn (User $actor, Location $context, string $organizationId): string => $organizationId,
            );
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
        }

        $this->assertSame($this->boss->organization_id, $result);
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('from "organizations"', strtolower($queries[0]['query']));
        $this->assertStringContainsString('for share', strtolower($queries[0]['query']));
    }

    public function test_revoked_membership_id_is_not_revived_by_a_new_membership_to_the_same_location(): void
    {
        $oldMembership = LocationMembership::query()
            ->where('user_id', $this->boss->id)
            ->where('location_id', $this->context->id)
            ->firstOrFail();
        app(MembershipService::class)->deactivate($oldMembership, 'Replace test membership', $this->boss->id);
        $newMembership = app(MembershipService::class)->assign($this->boss, $this->context, $this->boss->id);
        $this->assertNotSame($oldMembership->id, $newMembership->id);

        try {
            app(BossRawMaterialCatalogService::class)->withAuthorizedRead(
                $this->boss,
                $this->context,
                $oldMembership->id,
                $this->boss->auth_version,
                BossRawCatalogIntent::List,
                fn (): never => $this->fail('A revoked operating membership was accepted.'),
            );
            $this->fail('A revoked operating membership was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_identity_version_mismatch_is_rejected_after_acquiring_the_organization_lock(): void
    {
        try {
            app(BossRawMaterialCatalogService::class)->withAuthorizedRead(
                $this->boss,
                $this->context,
                $this->membershipId($this->boss),
                $this->boss->auth_version + 1,
                BossRawCatalogIntent::Detail,
                fn (): never => $this->fail('A stale identity version was accepted.'),
            );
            $this->fail('A stale identity version was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

    public function test_code_readiness_and_explicit_organization_state_are_both_required(): void
    {
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::notReady());
        $decision = app(BusinessVisibility::class)->decide(
            $this->boss,
            $this->context,
            $this->surface(BossRawCatalogIntent::Detail),
        );
        $this->assertFalse($decision->allowed);
        $this->assertSame('BOSS_CAPABILITY_UNAVAILABLE', $decision->reason);

        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        app(OrganizationCapabilityService::class)->configure(
            $this->administrator,
            $this->context,
            CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
            Str::uuid()->toString(),
            [
                'enabled' => false,
                'expected_state_version' => 1,
                'current_password' => self::PASSWORD,
            ],
        );
        $decision = app(BusinessVisibility::class)->decide(
            $this->boss,
            $this->context,
            $this->surface(BossRawCatalogIntent::List),
        );
        $this->assertFalse($decision->allowed);
        $this->assertSame('BOSS_CAPABILITY_UNAVAILABLE', $decision->reason);
    }

    public function test_global_raw_suppliers_restriction_denies_but_location_restrictions_do_not_change_organization_provenance(): void
    {
        $restrictions = app(BossLocationRestrictions::class);
        $global = $restrictions->replace(
            $this->administrator,
            $this->context,
            $this->boss->id,
            0,
            [],
            [BossVisibilityCategory::RawSuppliers->value],
            [],
        );
        $this->assertSame(1, $global['version']);
        $this->assertFalse(app(BusinessVisibility::class)->decide(
            $this->boss,
            $this->context,
            $this->surface(BossRawCatalogIntent::List),
        )->allowed);

        $restrictions->replace(
            $this->administrator,
            $this->context,
            $this->boss->id,
            1,
            [],
            [],
            [[
                'category' => BossVisibilityCategory::RawSuppliers->value,
                'location_id' => $this->context->id,
            ]],
        );
        $this->assertTrue(app(BusinessVisibility::class)->decide(
            $this->boss,
            $this->context,
            $this->surface(BossRawCatalogIntent::Detail),
        )->allowed);
    }

    public function test_surface_rejects_noncanonical_provenance_and_fake_permission_combinations(): void
    {
        foreach ([
            fn () => new BusinessVisibilitySurface(
                'boss.raw_catalog.list',
                BusinessVisibilitySource::Boss,
                'list',
                Permission::LocationView,
                [BossVisibilityCategory::RawSuppliers],
                BusinessDataProvenance::organization(),
                intent: BossRawCatalogIntent::List,
            ),
            fn () => new BusinessVisibilitySurface(
                'boss.raw_catalog.detail',
                BusinessVisibilitySource::Boss,
                'detail',
                null,
                [BossVisibilityCategory::ReportsAnalytics],
                BusinessDataProvenance::organization(),
                intent: BossRawCatalogIntent::Detail,
            ),
            fn () => new BusinessVisibilitySurface(
                'boss.raw_catalog.list',
                BusinessVisibilitySource::Boss,
                'list',
                null,
                [BossVisibilityCategory::RawSuppliers],
                BusinessDataProvenance::location($this->context->id),
                intent: BossRawCatalogIntent::List,
            ),
        ] as $invalidSurface) {
            try {
                $invalidSurface();
                $this->fail('Invalid BOSS intent surface was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    private function surface(BossRawCatalogIntent $intent): BusinessVisibilitySurface
    {
        return new BusinessVisibilitySurface(
            'boss.raw_catalog.'.$intent->value,
            BusinessVisibilitySource::Boss,
            $intent->value,
            null,
            [BossVisibilityCategory::RawSuppliers],
            BusinessDataProvenance::organization(),
            intent: $intent,
        );
    }

    private function membershipId(User $user): string
    {
        return (string) LocationMembership::query()
            ->where('user_id', $user->id)
            ->where('location_id', $this->context->id)
            ->where('is_active', true)
            ->value('id');
    }

    private function assertCatalogDeniedForBothIntents(User $actor, Location $context): void
    {
        $membershipId = (string) LocationMembership::query()
            ->where('user_id', $actor->id)
            ->where('location_id', $context->id)
            ->where('is_active', true)
            ->value('id');
        $this->assertNotSame('', $membershipId);

        $service = app(BossRawMaterialCatalogService::class);
        foreach ([BossRawCatalogIntent::List, BossRawCatalogIntent::Detail] as $intent) {
            $this->assertTrue(app(OrganizationCapabilityAccess::class)->isEnabled(
                $actor->organization_id,
                CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
                $intent->value,
                $context->type,
            ));

            try {
                if ($intent === BossRawCatalogIntent::List) {
                    $service->listing($actor, $context, $membershipId, $actor->auth_version, []);
                } else {
                    $service->detail($actor, $context, $membershipId, $actor->auth_version, Str::uuid()->toString());
                }
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());

                continue;
            }

            $this->fail("BOSS catalog {$intent->value} accepted an actor without the BOSS role.");
        }
    }

    /** @param list<RawMaterialPermission> $permissions */
    private function grantRawView(User $recipient, array $permissions = [RawMaterialPermission::View]): void
    {
        $this->updateFixture($recipient->organization, ['authorization_initialized' => true]);
        $operationId = Str::uuid()->toString();
        $this->withSetupTransaction(function (Connection $connection) use ($recipient, $operationId, $permissions): void {
            $connection->table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $recipient->organization_id,
                'operation_id' => $operationId,
                'actor_user_id' => $this->administrator->id,
                'subject_type' => 'ORGANIZATION',
                'subject_id' => $recipient->organization_id,
                'event_code' => 'PERMISSION_BOOTSTRAP',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'INITIAL_STEWARD',
                'metadata' => json_encode([
                    'recipient_user_id' => $recipient->id,
                    'permissions' => array_map(fn (RawMaterialPermission $permission): string => $permission->value, $permissions),
                ], JSON_THROW_ON_ERROR),
            ]);
            foreach ($permissions as $permission) {
                $connection->table('organization_permission_grants')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $recipient->organization_id,
                    'user_id' => $recipient->id,
                    'permission' => $permission->value,
                    'granted_by' => $this->administrator->id,
                    'valid_from' => now(),
                    'bootstrap_operation_id' => $operationId,
                    'reason_code' => 'INITIAL_STEWARD',
                ]);
            }
        });
    }
}
