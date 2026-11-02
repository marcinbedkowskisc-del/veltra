<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialPermissionBootstrap;
use App\Models\LocationModuleState;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
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

        try {
            $service->revoke($steward, $location, $viewGrant['id'], 'Próba unieważnienia grantu bootstrap');
            $this->fail('A steward revoked a bootstrap grant they did not issue.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('organization_permission_grant_events')
                ->where('grant_id', $viewGrant['id'])->count());
        }

        $service->revoke($steward, $location, $delegated['id'], 'Zakończenie delegacji');
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $delegated['id'])->where('event_type', 'REVOKED')->count());
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($recipient, RawMaterialPermission::View));
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $delegated['id'])->where('event_code', 'PERMISSION_REVOKED')->count());
    }
}
