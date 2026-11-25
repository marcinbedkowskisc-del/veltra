<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Authorization\SystemProvisioningRequestValidator;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\DefinedModuleCatalog;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\AuthorizationTestCase;

class SystemProvisioningRequestValidatorTest extends AuthorizationTestCase
{
    private User $administrator;

    private Location $primary;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primary = $this->kitchen();
        $initial = app(IdentityService::class)->provisionInitial([
            'first_name' => 'Initial', 'last_name' => 'Administrator', 'email' => 'initial@example.test',
        ], self::PASSWORD);
        app(MembershipService::class)->assign($initial, $this->primary);
        app(RoleAdministration::class)->bootstrapInitial($initial);
        $this->administrator = $this->updateFixture($initial, ['email_verified_at' => now()]);
        $this->updateFixture($this->administrator->organization, ['authorization_initialized' => true]);
        $this->organization = $this->administrator->organization->fresh();
    }

    public function test_valid_request_is_read_only_even_when_raw_is_not_ready(): void
    {
        $before = [
            DB::table('organization_permission_grants')->count(),
            DB::table('security_events')->count(),
            DB::table('raw_material_audit_events')->count(),
        ];
        $this->assertFalse((new DefinedModuleCatalog)->find('RAW_MATERIALS')->ready);

        $this->validateRequest(['RAW_MATERIALS_VIEW', 'RAW_MATERIALS_CREATE']);

        $this->assertSame($before, [
            DB::table('organization_permission_grants')->count(),
            DB::table('security_events')->count(),
            DB::table('raw_material_audit_events')->count(),
        ]);
    }

    public function test_invalid_permission_sets_and_unregistered_modules_are_rejected(): void
    {
        foreach ([
            ['RAW_MATERIALS_VIEW', 'FOODCOST_VIEW'],
            ['RAW_MATERIALS_VIEW', 'RAW_MATERIALS_VIEW'],
            [],
        ] as $permissions) {
            try {
                $this->validateRequest($permissions);
                $this->fail('Invalid permissions were accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        foreach (['INT', 'TEMPERATURES'] as $module) {
            try {
                $this->validateRequest(['RAW_MATERIALS_VIEW'], $module);
                $this->fail('A module without registered system permissions was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_unassigned_location_and_non_administrator_are_rejected(): void
    {
        $otherLocation = $this->activeRestaurant('SECONDARY');
        $otherUser = $this->provisionedUser('other@example.test', BusinessRole::CentralKitchenChef, $this->primary);

        foreach ([[$this->administrator, $otherLocation], [$otherUser, $this->primary]] as [$user, $location]) {
            try {
                app(SystemProvisioningRequestValidator::class)->validate(
                    $this->organization, $user, $location, 'RAW_MATERIALS', ['RAW_MATERIALS_VIEW'],
                );
                $this->fail('Invalid target was accepted.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_unknown_location_and_organization_targets_are_rejected(): void
    {
        $unknownLocation = new Location;
        $unknownLocation->id = Str::uuid()->toString();
        $unknownLocation->organization_id = Str::uuid()->toString();
        try {
            app(SystemProvisioningRequestValidator::class)->validate(
                $this->organization, $this->administrator, $unknownLocation, 'RAW_MATERIALS', ['RAW_MATERIALS_VIEW'],
            );
            $this->fail('An unknown location target was accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $unknownOrganization = new Organization;
        $unknownOrganization->id = Str::uuid()->toString();
        try {
            app(SystemProvisioningRequestValidator::class)->validate(
                $unknownOrganization, $this->administrator, $this->primary, 'RAW_MATERIALS', ['RAW_MATERIALS_VIEW'],
            );
            $this->fail('An unknown organization target was accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_inactive_or_unverified_administrator_is_rejected(): void
    {
        $other = $this->provisionedUser('other-admin@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($other, $this->primary, $this->administrator->id);

        foreach ([['is_active' => false], ['is_active' => true, 'email_verified_at' => null]] as $changes) {
            $other = $this->updateFixture($other, $changes);
            try {
                app(SystemProvisioningRequestValidator::class)->validate(
                    $this->organization, $other, $this->primary, 'RAW_MATERIALS', ['RAW_MATERIALS_VIEW'],
                );
                $this->fail('An inactive or unverified administrator was accepted.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_create_requires_the_primary_catalog_context_even_with_membership(): void
    {
        $secondary = $this->activeRestaurant('SECONDARY');
        app(MembershipService::class)->assign($this->administrator, $secondary, $this->administrator->id);

        $this->expectException(AuthorizationException::class);
        app(SystemProvisioningRequestValidator::class)->validate(
            $this->organization, $this->administrator, $secondary, 'RAW_MATERIALS', ['RAW_MATERIALS_CREATE'],
        );
    }

    private function validateRequest(array $permissions, string $module = 'RAW_MATERIALS'): void
    {
        app(SystemProvisioningRequestValidator::class)->validate(
            $this->organization, $this->administrator, $this->primary, $module, $permissions,
        );
    }
}
