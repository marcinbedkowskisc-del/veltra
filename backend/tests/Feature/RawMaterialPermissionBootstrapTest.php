<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialPermissionBootstrap;
use App\Domain\RawMaterials\RawMaterialPermissionUpgrade;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class RawMaterialPermissionBootstrapTest extends AuthorizationTestCase
{
    public function test_initial_grant_is_explicit_audited_one_time_and_never_self_granted(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('bootstrap-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $recipient = $this->user('bootstrap-steward@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        Organization::whereKey($administrator->organization_id)->update(['authorization_initialized' => true]);
        $permissions = [
            RawMaterialPermission::View->value,
            RawMaterialPermission::PermissionDelegate->value,
        ];

        $grants = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $administrator->id,
            $recipient->id,
            $permissions,
            'Pierwsze nadanie opiekunowi',
            self::PASSWORD,
        );

        $this->assertCount(2, $grants);
        $this->assertSame(2, DB::table('organization_permission_grants')
            ->where('organization_id', $administrator->organization_id)->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('organization_id', $administrator->organization_id)
            ->where('event_code', 'PERMISSION_BOOTSTRAP')->count());

        foreach ([
            fn () => app(RawMaterialPermissionBootstrap::class)->grantInitial(
                $administrator->id,
                $recipient->id,
                $permissions,
                'Drugie nadanie',
                self::PASSWORD,
            ),
            fn () => app(RawMaterialPermissionBootstrap::class)->grantInitial(
                $administrator->id,
                $administrator->id,
                $permissions,
                'Próba samonadania',
                self::PASSWORD,
            ),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An unauthorized bootstrap grant was accepted.');
            } catch (AuthorizationException) {
                $this->assertSame(2, DB::table('organization_permission_grants')
                    ->where('organization_id', $administrator->organization_id)->count());
            }
        }
    }

    public function test_bootstrap_rejects_unknown_permissions_and_invalid_passwords(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('bootstrap-invalid-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $recipient = $this->user('bootstrap-invalid-steward@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        Organization::whereKey($administrator->organization_id)->update(['authorization_initialized' => true]);

        foreach ([
            fn () => app(RawMaterialPermissionBootstrap::class)->grantInitial(
                $administrator->id,
                $recipient->id,
                ['RAW_MATERIALS_ROOT'],
                'Nieznane uprawnienie',
                self::PASSWORD,
            ),
            fn () => app(RawMaterialPermissionBootstrap::class)->grantInitial(
                $administrator->id,
                $recipient->id,
                [RawMaterialPermission::PermissionDelegate->value],
                'Nieprawidłowe hasło',
                'Nieprawidłowe-Hasło!99',
            ),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An invalid bootstrap request was accepted.');
            } catch (AuthorizationException) {
                $this->assertSame(0, DB::table('organization_permission_grants')
                    ->where('organization_id', $administrator->organization_id)->count());
            }
        }
    }

    public function test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('permission-upgrade-bootstrap-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $steward = $this->user('permission-upgrade-steward@example.test');
        app(MembershipService::class)->assign($steward, $location);
        $this->role($steward, BusinessRole::Administrator);
        $recipient = $this->user('permission-upgrade-recipient@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        Organization::whereKey($administrator->organization_id)->update(['authorization_initialized' => true]);
        $initialGrants = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $administrator->id,
            $steward->id,
            [RawMaterialPermission::View->value, RawMaterialPermission::PermissionDelegate->value],
            'Jawne ustanowienie opiekuna',
            self::PASSWORD,
        );
        $authorityGrant = collect($initialGrants)
            ->firstWhere('permission', RawMaterialPermission::PermissionDelegate->value);
        $permissions = [
            RawMaterialPermission::PurchasingCostsView->value,
            RawMaterialPermission::SupplierView->value,
        ];
        $upgrade = app(RawMaterialPermissionUpgrade::class);
        try {
            $upgrade->grant(
                $steward->id,
                $recipient->id,
                $permissions,
                'Uwierzytelnienie musi być ponowione',
                'stage2c-upgrade-badpass',
                'Nieprawidłowe-Hasło!99',
            );
            $this->fail('A permission upgrade with an incorrect password must be denied.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('raw_material_permission_upgrade_operations')->count());
        }

        $result = $upgrade->grant(
            $steward->id,
            $recipient->id,
            $permissions,
            'Zatwierdzony zakres dla organizacji już działającej',
            'stage2c-upgrade-0001',
            self::PASSWORD,
        );

        $this->assertFalse($result['replayed']);
        $this->assertCount(2, $result['grants']);
        $this->assertSame($authorityGrant['id'], DB::table('raw_material_permission_upgrade_operations')
            ->where('id', $result['operation_id'])->value('authority_grant_id'));
        $this->assertSame($permissions, DB::table('organization_permission_grants')
            ->where('bootstrap_operation_id', $result['operation_id'])
            ->orderBy('permission')->pluck('permission')->all());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('operation_id', $result['operation_id'])
            ->where('event_code', 'PERMISSION_UPGRADE')
            ->where('actor_user_id', $steward->id)
            ->where('outcome', 'ACCEPTED')->count());
        $this->assertSame(2, count(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($recipient, RawMaterialPermission::SupplierView))
            + count(app(RawMaterialAuthorization::class)
                ->effectiveGrantIds($recipient, RawMaterialPermission::PurchasingCostsView)));
        $this->assertSame(0, LocationModuleState::where('organization_id', $administrator->organization_id)
            ->where('module_key', 'RAW_MATERIALS')->count());

        $retry = $upgrade->grant(
            $steward->id,
            $recipient->id,
            array_reverse($permissions),
            'Zatwierdzony zakres dla organizacji już działającej',
            'stage2c-upgrade-0001',
            self::PASSWORD,
        );
        $this->assertTrue($retry['replayed']);
        $this->assertSame($result['operation_id'], $retry['operation_id']);
        $this->assertSame($result['grants'], $retry['grants']);
        $this->assertSame(2, DB::table('organization_permission_grants')
            ->where('bootstrap_operation_id', $result['operation_id'])->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('operation_id', $result['operation_id'])->where('event_code', 'PERMISSION_UPGRADE')->count());

        try {
            $upgrade->grant(
                $steward->id,
                $recipient->id,
                [RawMaterialPermission::SupplierManage->value],
                'Inna lista dla istniejącego klucza',
                'stage2c-upgrade-0001',
                self::PASSWORD,
            );
            $this->fail('A reused idempotency key with changed permissions must conflict.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_IDEMPOTENCY_CONFLICT', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        try {
            DB::transaction(fn () => DB::table('raw_material_permission_upgrade_operations')
                ->where('id', $result['operation_id'])->update(['reason' => 'Niedozwolona zmiana']));
            $this->fail('Permission-upgrade operations must be append-only.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => DB::table('raw_material_audit_events')
                ->where('operation_id', $result['operation_id'])->update(['reason_code' => 'Niedozwolona zmiana']));
            $this->fail('Permission-upgrade audit events must be append-only.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }

        User::whereKey($steward->id)->update(['is_active' => false]);
        $grantIds = collect($result['grants'])->pluck('id');
        $this->assertSame($grantIds->count(), DB::table('organization_permission_grant_events')
            ->whereIn('grant_id', $grantIds)->where('event_type', 'REVOKED')->count());
        User::whereKey($steward->id)->update(['is_active' => true]);
        $this->assertSame([], app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($recipient, RawMaterialPermission::SupplierView));
        $this->assertSame([], app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($recipient, RawMaterialPermission::PurchasingCostsView));
    }

    public function test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('permission-upgrade-denied-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $recipient = $this->user('permission-upgrade-denied-recipient@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        Organization::whereKey($administrator->organization_id)->update(['authorization_initialized' => true]);
        $service = app(RawMaterialPermissionUpgrade::class);

        try {
            $service->grant(
                $administrator->id,
                $recipient->id,
                [RawMaterialPermission::SupplierManage->value],
                'Brak prawa delegowania',
                'stage2c-upgrade-denied',
                self::PASSWORD,
            );
            $this->fail('An administrator without permission-delegation authority must be denied.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('raw_material_permission_upgrade_operations')->count());
            $this->assertSame(0, DB::table('organization_permission_grants')->count());
        }

        try {
            DB::table('organization_permission_grants')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $administrator->organization_id,
                'user_id' => $recipient->id,
                'permission' => RawMaterialPermission::SupplierManage->value,
                'granted_by' => $administrator->id,
                'valid_from' => now(),
                'delegation_source_id' => null,
                'bootstrap_operation_id' => (string) Str::uuid(),
                'reason_code' => 'DIRECT_SQL_PROBE',
            ]);
            $this->fail('A root grant without an audited bootstrap or upgrade operation must be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    public function test_delegation_is_limited_to_effective_authority_and_revocation_is_audited(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('delegation-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $steward = $this->user('delegation-steward@example.test');
        app(MembershipService::class)->assign($steward, $location);
        $recipient = $this->user('delegation-recipient@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        $suspensionRecipient = $this->user('suspension-delegation-recipient@example.test');
        app(MembershipService::class)->assign($suspensionRecipient, $location);
        Organization::whereKey($administrator->organization_id)->update(['authorization_initialized' => true]);
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        LocationModuleState::create([
            'organization_id' => $administrator->organization_id,
            'location_id' => $location->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);
        $bootstrapGrants = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $administrator->id,
            $steward->id,
            [RawMaterialPermission::View->value, RawMaterialPermission::PermissionDelegate->value],
            'Pierwsze nadanie opiekunowi',
            self::PASSWORD,
        );
        $viewGrant = collect($bootstrapGrants)->firstWhere('permission', RawMaterialPermission::View->value);
        $service = app(OrganizationRawMaterialPermissionAdministration::class);
        $delegated = $service->grant(
            $steward,
            $location,
            $recipient,
            RawMaterialPermission::View,
            'Delegowanie odczytu katalogu',
        );

        $this->assertSame($viewGrant['id'], DB::table('organization_permission_grants')
            ->where('id', $delegated['id'])->value('delegation_source_id'));
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($recipient, RawMaterialPermission::View));
        $service->revoke($steward, $location, $delegated['id'], 'Jawne odwołanie delegacji');
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $delegated['id'])->where('event_type', 'REVOKED')->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $delegated['id'])->where('event_code', 'PERMISSION_REVOKED')
            ->where('actor_user_id', $steward->id)->count());
        $delegatedForSuspension = $service->grant(
            $steward,
            $location,
            $suspensionRecipient,
            RawMaterialPermission::View,
            'Delegowanie odbiorcy objętego zawieszeniem grantora',
        );
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($suspensionRecipient, RawMaterialPermission::View));
        try {
            $service->grant(
                $steward,
                $location,
                $recipient,
                RawMaterialPermission::PermissionDelegate,
                'Próba delegacji uprawnienia delegowania',
            );
            $this->fail('The delegation permission was delegated.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('organization_permission_grants')
                ->where('user_id', $recipient->id)->count());
        }

        $contextResponse = $this->actingAs($recipient, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $location->id,
        ]);
        $this->assertSame(200, $contextResponse->status(), $contextResponse->getContent());
        User::whereKey($steward->id)->update(['is_active' => false]);
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($suspensionRecipient, RawMaterialPermission::View));
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $viewGrant['id'])->where('reason_code', 'USER_DEACTIVATED')->count());
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $delegatedForSuspension['id'])->where('reason_code', 'USER_DEACTIVATED')->count());
        $this->assertSame(2, DB::table('raw_material_audit_events')
            ->whereIn('subject_id', [$viewGrant['id'], $delegatedForSuspension['id']])
            ->where('event_code', 'PERMISSION_REVOKED')
            ->where('reason_code', 'USER_DEACTIVATED')
            ->whereNull('actor_user_id')->count());
        $this->getJson('/api/v1/raw-materials')->assertForbidden();
        try {
            DB::transaction(fn () => DB::table('organization_permission_grants')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $steward->organization_id,
                'user_id' => $administrator->id,
                'permission' => RawMaterialPermission::View->value,
                'granted_by' => $steward->id,
                'valid_from' => now(),
                'delegation_source_id' => $viewGrant['id'],
                'bootstrap_operation_id' => null,
                'reason_code' => 'DIRECT_SQL_PROBE',
            ]));
            $this->fail('A suspended grantor created a delegated grant through direct SQL.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0] ?? null);
        }
        User::whereKey($steward->id)->update(['is_active' => true]);
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($suspensionRecipient, RawMaterialPermission::View));

        $stewardMembership = app(MembershipService::class)->available($steward)
            ->where('location_id', $location->id)->firstOrFail();
        app(MembershipService::class)->deactivate($stewardMembership, 'Członkostwo testowe zawieszone');
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($suspensionRecipient, RawMaterialPermission::View));
        app(MembershipService::class)->assign($steward->fresh(), $location);
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($suspensionRecipient, RawMaterialPermission::View));

        try {
            $service->grant(
                $steward,
                $location,
                $recipient,
                RawMaterialPermission::Edit,
                'Próba eskalacji uprawnień',
            );
            $this->fail('A permission outside the steward authority was delegated.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('organization_permission_grants')
                ->where('user_id', $recipient->id)->count());
        }

        $this->assertSame(0, DB::table('organization_permission_grant_events')
            ->where('grant_id', $delegatedForSuspension['id'])->where('reason_code', 'USER_PROVIDED')->count());
    }

    public function test_membership_and_user_reactivation_never_restore_revoked_raw_material_grants(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('permanent-revoke-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $steward = $this->user('permanent-revoke-steward@example.test');
        app(MembershipService::class)->assign($steward, $location);
        $recipient = $this->user('permanent-revoke-recipient@example.test');
        $recipientMembership = app(MembershipService::class)->assign($recipient, $location);
        Organization::whereKey($administrator->organization_id)->update(['authorization_initialized' => true]);
        $this->enableRawMaterials($administrator, $location);

        $bootstrap = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $administrator->id,
            $steward->id,
            [RawMaterialPermission::View->value, RawMaterialPermission::PermissionDelegate->value],
            'Nadanie do testu trwałego odwołania',
            self::PASSWORD,
        );
        $sourceGrant = collect($bootstrap)->firstWhere('permission', RawMaterialPermission::View->value);
        $permissions = app(OrganizationRawMaterialPermissionAdministration::class);
        $firstDelegation = $permissions->grant(
            $steward,
            $location,
            $recipient,
            RawMaterialPermission::View,
            'Pierwsze delegowanie',
        );
        $authorization = app(RawMaterialAuthorization::class);
        $this->assertNotEmpty($authorization->effectiveGrantIds($recipient, RawMaterialPermission::View));

        try {
            DB::transaction(function () use ($recipientMembership): void {
                app(MembershipService::class)->deactivate($recipientMembership, 'Zawieszenie wycofane przez rollback');
                throw new \RuntimeException('rollback fixture');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback fixture', $exception->getMessage());
        }
        $this->assertTrue($recipientMembership->fresh()->is_active);
        $this->assertSame(0, DB::table('organization_permission_grant_events')
            ->where('grant_id', $firstDelegation['id'])->count());
        $this->assertNotEmpty($authorization->effectiveGrantIds($recipient, RawMaterialPermission::View));

        app(MembershipService::class)->deactivate($recipientMembership, 'Zawieszenie członkostwa');
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $firstDelegation['id'])
            ->where('reason_code', 'MEMBERSHIP_DEACTIVATED')->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $firstDelegation['id'])
            ->where('event_code', 'PERMISSION_REVOKED')
            ->where('reason_code', 'MEMBERSHIP_DEACTIVATED')
            ->whereJsonContains('metadata', [
                'source_type' => 'MEMBERSHIP',
                'source_id' => $recipientMembership->id,
                'affected_user_id' => $recipient->id,
            ])->count());

        app(MembershipService::class)->assign($recipient->fresh(), $location);
        $this->assertEmpty($authorization->effectiveGrantIds($recipient, RawMaterialPermission::View));

        $secondDelegation = $permissions->grant(
            $steward,
            $location,
            $recipient->fresh(),
            RawMaterialPermission::View,
            'Jawne ponowne nadanie po wznowieniu członkostwa',
        );
        $this->assertNotSame($firstDelegation['id'], $secondDelegation['id']);
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $secondDelegation['id'])
            ->where('event_code', 'PERMISSION_GRANTED')
            ->where('actor_user_id', $steward->id)->count());
        $this->assertSame([$secondDelegation['id']], $authorization->effectiveGrantIds(
            $recipient->fresh(),
            RawMaterialPermission::View,
        ));

        $permissions->revoke($steward, $location, $secondDelegation['id'], 'Test jawnego cofnięcia po wznowieniu');
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $secondDelegation['id'])->count());
        $this->assertEmpty($authorization->effectiveGrantIds($recipient->fresh(), RawMaterialPermission::View));
        $thirdDelegation = $permissions->grant(
            $steward,
            $location,
            $recipient->fresh(),
            RawMaterialPermission::View,
            'Jawne nadanie po cofnięciu poprzedniego grantu',
        );

        app(IdentityService::class)->disable($recipient->fresh());
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $thirdDelegation['id'])
            ->where('reason_code', 'USER_DEACTIVATED')->count());
        User::whereKey($recipient->id)->update(['is_active' => true]);
        $this->assertEmpty($authorization->effectiveGrantIds($recipient->fresh(), RawMaterialPermission::View));
        $this->assertSame(0, DB::table('organization_permission_grant_events')
            ->where('grant_id', $sourceGrant['id'])->count());
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $thirdDelegation['id'])->whereNull('actor_user_id')->count());
    }

    public function test_delegated_grants_expire_with_source_permission_and_delegation_authority(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('expiry-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $steward = $this->user('expiry-steward@example.test');
        app(MembershipService::class)->assign($steward, $location);
        $viewRecipient = $this->user('expiry-view-recipient@example.test');
        app(MembershipService::class)->assign($viewRecipient, $location);
        $createRecipient = $this->user('expiry-create-recipient@example.test');
        app(MembershipService::class)->assign($createRecipient, $location);
        Organization::whereKey($administrator->organization_id)->update(['authorization_initialized' => true]);
        $this->enableRawMaterials($administrator, $location);

        $viewExpiresAt = now()->addSeconds(30);
        $delegateExpiresAt = now()->addSeconds(40);
        $bootstrap = $this->insertBootstrapGrants($administrator, $steward, [
            RawMaterialPermission::View->value => $viewExpiresAt,
            RawMaterialPermission::Create->value => null,
            RawMaterialPermission::PermissionDelegate->value => $delegateExpiresAt,
        ]);
        $service = app(OrganizationRawMaterialPermissionAdministration::class);
        $viewGrant = $service->grant(
            $steward,
            $location,
            $viewRecipient,
            RawMaterialPermission::View,
            'Delegacja z wygasającym źródłem',
            null,
            $viewExpiresAt->copy()->subSecond()->toISOString(),
        );
        $createGrant = $service->grant(
            $steward,
            $location,
            $createRecipient,
            RawMaterialPermission::Create,
            'Delegacja z wygasającym prawem delegowania',
            null,
            $delegateExpiresAt->copy()->subSecond()->toISOString(),
        );
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($viewRecipient, RawMaterialPermission::View));
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($createRecipient, RawMaterialPermission::Create));

        sleep(31);
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($viewRecipient, RawMaterialPermission::View));
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($createRecipient, RawMaterialPermission::Create));
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($steward, RawMaterialPermission::PermissionDelegate));

        sleep(10);
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($createRecipient, RawMaterialPermission::Create));
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($steward, RawMaterialPermission::PermissionDelegate));
        $this->assertSame(0, DB::table('organization_permission_grant_events')
            ->whereIn('grant_id', [$viewGrant['id'], $createGrant['id']])->count());
        $this->assertCount(3, $bootstrap);
    }

    private function enableRawMaterials(User $administrator, Location $location): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        LocationModuleState::create([
            'organization_id' => $administrator->organization_id,
            'location_id' => $location->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);
    }

    /**
     * @param  array<string, Carbon|null>  $expiries
     * @return list<array{id: string, permission: string}>
     */
    private function insertBootstrapGrants(User $administrator, User $recipient, array $expiries): array
    {
        $operationId = Str::uuid()->toString();
        $permissions = array_keys($expiries);
        DB::table('raw_material_audit_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $administrator->organization_id,
            'operation_id' => $operationId,
            'actor_user_id' => $administrator->id,
            'subject_type' => 'ORGANIZATION',
            'subject_id' => $administrator->organization_id,
            'event_code' => 'PERMISSION_BOOTSTRAP',
            'outcome' => 'ACCEPTED',
            'reason_code' => 'INITIAL_STEWARD',
            'metadata' => json_encode([
                'recipient_user_id' => $recipient->id,
                'permissions' => $permissions,
            ], JSON_THROW_ON_ERROR),
        ]);

        $grants = [];
        foreach ($expiries as $permission => $validUntil) {
            $id = Str::uuid()->toString();
            DB::table('organization_permission_grants')->insert([
                'id' => $id,
                'organization_id' => $administrator->organization_id,
                'user_id' => $recipient->id,
                'permission' => $permission,
                'granted_by' => $administrator->id,
                'valid_from' => now()->subSecond(),
                'valid_until' => $validUntil,
                'delegation_source_id' => null,
                'bootstrap_operation_id' => $operationId,
                'reason_code' => 'INITIAL_STEWARD',
            ]);
            $grants[] = ['id' => $id, 'permission' => $permission];
        }

        return $grants;
    }
}
