<?php

namespace Tests\Feature;

use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocationType;
use App\Models\Location;
use App\Models\LocationManagementAssignment;
use App\Models\LocationSupplyRelationship;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\InstallationTestCase;

class InstallationDomainTest extends InstallationTestCase
{
    public function test_initialization_creates_a_single_uuid_organization(): void
    {
        $organization = $this->initialize();
        $this->assertTrue(Str::isUuid($organization->id));
        $this->assertTrue($organization->is_active);
        $this->assertSame(1, Organization::count());
    }

    public function test_service_rejects_a_second_organization_even_when_the_first_is_inactive(): void
    {
        $organization = $this->initialize();
        $organization->update(['is_active' => false]);
        $this->expectException(BusinessRuleViolation::class);
        $this->service->initializeOrganization(['name' => 'Second', 'code' => 'SECOND']);
    }

    public function test_postgresql_also_prevents_a_second_organization(): void
    {
        $this->initialize();
        $this->assertDatabaseRejects('23505', fn () => Organization::create(['name' => 'Second', 'code' => 'SECOND']));
    }

    public function test_locations_have_explicit_types_and_eloquent_relationships(): void
    {
        $organization = $this->initialize();
        $kitchen = $this->kitchen();
        $restaurant = $this->restaurant();
        $this->assertTrue($kitchen->is_active);
        $this->assertFalse($restaurant->is_active);
        $this->assertSame(LocationType::CentralKitchen, $kitchen->type);
        $this->assertSame(LocationType::Restaurant, $restaurant->type);
        $this->assertTrue($restaurant->organization->is($organization));
        $this->assertSame(2, $organization->locations()->count());
        $this->service->updateLocation($restaurant, ['name' => 'Renamed', 'code' => 'R2']);
        $this->assertSame('Renamed', $restaurant->fresh()->name);
    }

    public function test_database_rejects_invalid_types_duplicate_codes_and_identity_changes(): void
    {
        $organization = $this->initialize();
        $kitchen = $this->kitchen();
        $this->assertDatabaseRejects('23514', fn () => DB::table('locations')->insert([
            'id' => Str::uuid()->toString(), 'organization_id' => $organization->id,
            'name' => 'Invalid', 'code' => 'INVALID', 'type' => 'WAREHOUSE',
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertDatabaseRejects('23505', fn () => Location::create([
            'organization_id' => $organization->id, 'name' => 'Duplicate', 'code' => 'CK1', 'type' => 'RESTAURANT',
        ]));
        $this->assertDatabaseRejects('23514', fn () => $kitchen->update(['type' => 'RESTAURANT']));
    }

    public function test_initial_restaurant_can_be_unassigned_but_cannot_be_activated(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $this->assertNull($this->service->currentManagingKitchen($restaurant));
        $this->expectException(BusinessRuleViolation::class);
        $this->service->updateLocation($restaurant, ['is_active' => true]);
    }

    public function test_database_checks_activation_at_transaction_end_and_allows_assigning_in_the_same_transaction(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchen();
        $this->assertDatabaseRejects('23514', fn () => DB::table('locations')->where('id', $restaurant->id)->update(['is_active' => true]));

        DB::transaction(function () use ($restaurant, $kitchen): void {
            DB::table('locations')->where('id', $restaurant->id)->update(['is_active' => true]);
            $this->service->assignManagingKitchen($restaurant, $kitchen);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $this->assertTrue($restaurant->fresh()->is_active);
    }

    public function test_assigning_and_changing_management_preserves_history_and_activation(): void
    {
        $organization = $this->initialize();
        $restaurant = $this->restaurant();
        $firstKitchen = $this->kitchen();
        $secondKitchen = $this->kitchen('CK2');
        $first = $this->service->assignManagingKitchen($restaurant, $firstKitchen);
        $this->service->updateLocation($restaurant, ['is_active' => true]);
        $second = $this->service->assignManagingKitchen($restaurant, $secondKitchen, replace: true);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $first->refresh();
        $this->assertNotNull($first->effective_until);
        $this->assertTrue($first->effective_until->equalTo($second->effective_from));
        $this->assertTrue($first->managingKitchen->is($firstKitchen));
        $this->assertTrue($second->restaurant->is($restaurant));
        $this->assertTrue($second->organization->is($organization));
        $this->assertSame(2, $this->service->managementHistory($restaurant, 20)->total());
        $this->assertTrue($this->service->currentManagingKitchen($restaurant)->is($secondKitchen));
        $this->assertSame(0, $this->service->managedRestaurants($firstKitchen, 20)->total());
        $this->assertSame(1, $this->service->managedRestaurants($secondKitchen, 20)->total());
        $this->assertTrue($restaurant->fresh()->is_active);
    }

    public function test_service_rejects_assigning_a_second_current_manager(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $this->service->assignManagingKitchen($restaurant, $this->kitchen());
        $second = $this->kitchen('CK2');
        $this->expectException(BusinessRuleViolation::class);
        $this->service->assignManagingKitchen($restaurant, $second);
    }

    public function test_database_prevents_two_open_management_periods(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $first = $this->service->assignManagingKitchen($restaurant, $this->kitchen());
        $second = $this->kitchen('CK2');
        $this->assertDatabaseRejects('23P01', fn () => LocationManagementAssignment::create([
            'organization_id' => $restaurant->organization_id, 'restaurant_id' => $restaurant->id,
            'managing_kitchen_id' => $second->id, 'effective_from' => $first->effective_from->addSecond(),
        ]));
    }

    public function test_database_rejects_overlapping_history_but_allows_adjacent_periods(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchen();
        $base = ['organization_id' => $restaurant->organization_id, 'restaurant_id' => $restaurant->id, 'managing_kitchen_id' => $kitchen->id];
        LocationManagementAssignment::create([...$base, 'effective_from' => '2025-01-01T00:00:00Z', 'effective_until' => '2025-02-01T00:00:00Z']);
        $this->assertDatabaseRejects('23P01', fn () => LocationManagementAssignment::create([
            ...$base, 'effective_from' => '2025-01-15T00:00:00Z', 'effective_until' => '2025-03-01T00:00:00Z',
        ]));
        LocationManagementAssignment::create([...$base, 'effective_from' => '2025-02-01T00:00:00Z', 'effective_until' => '2025-03-01T00:00:00Z']);
        $this->assertSame(2, $restaurant->managementAssignments()->count());
    }

    public function test_database_preserves_history_and_rejects_empty_periods(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchen();
        $first = $this->service->assignManagingKitchen($restaurant, $kitchen);
        $this->assertDatabaseRejects('23514', fn () => $first->delete());
        $this->assertDatabaseRejects('23514', fn () => $first->update(['effective_from' => now()->subDay()]));
        $this->assertDatabaseRejects('23514', fn () => $first->fresh()->update(['effective_until' => $first->effective_from]));
        $this->service->assignManagingKitchen($restaurant, $this->kitchen('CK2'), replace: true);
        $this->assertDatabaseRejects('23514', fn () => $first->fresh()->update(['effective_until' => now()->addDay()]));
    }

    public function test_only_active_kitchens_can_manage_operational_restaurants(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchen();
        $this->service->assignManagingKitchen($restaurant, $kitchen);
        $this->service->updateLocation($restaurant, ['is_active' => true]);
        $this->assertDatabaseRejects('23514', fn () => DB::table('locations')->where('id', $kitchen->id)->update(['is_active' => false]));
        $this->expectException(BusinessRuleViolation::class);
        $this->service->updateLocation($kitchen, ['is_active' => false]);
    }

    public function test_invalid_management_types_are_rejected_by_service_and_database(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchen();
        $this->assertDatabaseRejects('23514', fn () => LocationManagementAssignment::create([
            'organization_id' => $restaurant->organization_id, 'restaurant_id' => $kitchen->id,
            'managing_kitchen_id' => $restaurant->id, 'effective_from' => now(),
        ]));
        $this->expectException(BusinessRuleViolation::class);
        $this->service->assignManagingKitchen($kitchen, $restaurant);
    }

    public function test_multiple_suppliers_are_independent_from_management_and_can_be_disabled(): void
    {
        $organization = $this->initialize();
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchen();
        $secondKitchen = $this->kitchen('CK2');
        $first = $this->service->establishSupply($restaurant, $kitchen);
        $second = $this->service->establishSupply($restaurant, $secondKitchen);
        $this->assertSame(2, $this->service->authorizedSuppliers($restaurant, 20)->total());
        $this->assertSame(0, $restaurant->managementAssignments()->count());
        $this->assertNull($this->service->currentManagingKitchen($restaurant));
        $this->assertTrue($first->supplierKitchen->is($kitchen));
        $this->assertTrue($first->restaurant->is($restaurant));
        $this->assertTrue($first->organization->is($organization));
        $assignment = $this->service->assignManagingKitchen($restaurant, $secondKitchen);
        $this->service->disableSupply($second);
        $this->service->disableSupply($second);
        $this->assertSame(1, $this->service->authorizedSuppliers($restaurant, 20)->total());
        $this->assertTrue($this->service->currentManagingKitchen($restaurant)->is($secondKitchen));
        $this->assertSame(1, $restaurant->managementAssignments()->count());
        $this->assertNull($assignment->fresh()->effective_until);
        $renewed = $this->service->establishSupply($restaurant, $secondKitchen);
        $this->assertNotSame($second->id, $renewed->id);
        $this->assertSame(3, $this->service->supplyRelationships($restaurant, 20)->total());
    }

    public function test_duplicate_active_supply_is_rejected_by_service_and_database(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchen();
        $this->service->establishSupply($restaurant, $kitchen);
        $this->assertDatabaseRejects('23505', fn () => LocationSupplyRelationship::create([
            'organization_id' => $restaurant->organization_id, 'restaurant_id' => $restaurant->id,
            'supplier_kitchen_id' => $kitchen->id, 'is_active' => true,
        ]));
        $this->expectException(BusinessRuleViolation::class);
        $this->service->establishSupply($restaurant, $kitchen);
    }

    public function test_supply_cannot_use_invalid_location_types(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchen();
        $this->assertDatabaseRejects('23514', fn () => LocationSupplyRelationship::create([
            'organization_id' => $restaurant->organization_id, 'restaurant_id' => $kitchen->id,
            'supplier_kitchen_id' => $restaurant->id, 'is_active' => true,
        ]));
        $this->expectException(BusinessRuleViolation::class);
        $this->service->establishSupply($restaurant, $restaurant);
    }

    public function test_cross_organization_management_and_supply_are_rejected(): void
    {
        $organization = $this->initialize();
        $restaurant = $this->restaurant();
        // A rolled-back adversarial fixture bypasses ONLY the singleton constraint.
        // Relationship constraints and application validation remain enabled.
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT organizations_single_installation_unique');
        $other = Organization::create(['name' => 'Adversarial fixture', 'code' => 'OTHER', 'is_active' => false]);
        $foreignKitchen = Location::create([
            'organization_id' => $other->id, 'name' => 'Foreign', 'code' => 'FOREIGN',
            'type' => 'CENTRAL_KITCHEN', 'is_active' => true,
        ]);
        $this->assertDatabaseRejects('23503', fn () => LocationManagementAssignment::create([
            'organization_id' => $organization->id, 'restaurant_id' => $restaurant->id,
            'managing_kitchen_id' => $foreignKitchen->id, 'effective_from' => now(),
        ]));
        $this->assertDatabaseRejects('23503', fn () => LocationSupplyRelationship::create([
            'organization_id' => $organization->id, 'restaurant_id' => $restaurant->id,
            'supplier_kitchen_id' => $foreignKitchen->id, 'is_active' => true,
        ]));
        foreach (['assignManagingKitchen', 'establishSupply'] as $method) {
            try {
                $this->service->$method($restaurant, $foreignKitchen);
                $this->fail('Cross-organization relationship was accepted.');
            } catch (BusinessRuleViolation $exception) {
                $this->assertSame('Locations must belong to the installation organization.', $exception->getMessage());
            }
        }
    }
}
