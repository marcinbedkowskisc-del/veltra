<?php

namespace App\Domain\Installation;

use App\Models\Location;
use App\Models\LocationManagementAssignment;
use App\Models\LocationSupplyRelationship;
use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstallationService
{
    public function initializeOrganization(array $attributes): Organization
    {
        return DB::transaction(function () use ($attributes): Organization {
            // Also serializes initialization before an organization row exists.
            DB::select('SELECT pg_advisory_xact_lock(8612001)');
            if (Organization::exists()) {
                throw new BusinessRuleViolation('The installation organization has already been initialized.');
            }

            return Organization::create($attributes);
        });
    }

    public function createLocation(array $attributes): Location
    {
        return DB::transaction(function () use ($attributes): Location {
            $organization = $this->lockOrganization();
            $attributes['is_active'] ??= $attributes['type'] === LocationType::CentralKitchen->value;
            if ($attributes['type'] === LocationType::Restaurant->value && $attributes['is_active']) {
                throw new BusinessRuleViolation('Create the restaurant inactive, assign a managing kitchen, then activate it.');
            }
            $this->ensureCodeAvailable($organization, $attributes['code']);

            return $organization->locations()->create($attributes);
        });
    }

    public function updateLocation(Location $location, array $attributes): Location
    {
        return DB::transaction(function () use ($location, $attributes): Location {
            $organization = $this->lockOrganization();
            $location = Location::findOrFail($location->id);
            $this->ensureOwned($organization, $location);
            if (isset($attributes['code'])) {
                $this->ensureCodeAvailable($organization, $attributes['code'], $location);
            }
            $active = $attributes['is_active'] ?? $location->is_active;
            if ($location->type === LocationType::Restaurant && $active) {
                $assignment = $location->managementAssignments()->current()->with('managingKitchen')->first();
                if (! $assignment || ! $assignment->managingKitchen->is_active) {
                    throw new BusinessRuleViolation('An active restaurant requires a current active managing kitchen.');
                }
            }
            if ($location->type === LocationType::CentralKitchen && ! $active
                && $location->managedAssignments()->current()->whereHas('restaurant', fn (Builder $q) => $q->where('is_active', true))->exists()) {
                throw new BusinessRuleViolation('Reassign or deactivate managed active restaurants before disabling this kitchen.');
            }
            $location->update($attributes);

            return $location->refresh();
        });
    }

    public function assignManagingKitchen(Location $restaurant, Location $kitchen, bool $replace = false): LocationManagementAssignment
    {
        return DB::transaction(function () use ($restaurant, $kitchen, $replace): LocationManagementAssignment {
            $organization = $this->lockOrganization();
            $restaurant = Location::findOrFail($restaurant->id);
            $kitchen = Location::findOrFail($kitchen->id);
            $this->validatePair($organization, $restaurant, $kitchen);
            if (! $kitchen->is_active) {
                throw new BusinessRuleViolation('The managing kitchen must be active.');
            }
            $current = $restaurant->managementAssignments()->current()->first();
            if ($replace && ! $current) {
                throw new BusinessRuleViolation('There is no current managing kitchen to change.');
            }
            if (! $replace && $current) {
                throw new BusinessRuleViolation('The restaurant already has a managing kitchen. Use the change operation.');
            }
            if ($current && $current->managing_kitchen_id === $kitchen->id) {
                throw new BusinessRuleViolation('This kitchen already manages the restaurant.');
            }
            // Generate the boundary after acquiring the lock; preserve sub-second precision.
            $boundary = DB::scalar('SELECT clock_timestamp()');
            if ($current) {
                $current->update(['effective_until' => $boundary]);
            }

            return LocationManagementAssignment::create([
                'organization_id' => $organization->id,
                'restaurant_id' => $restaurant->id,
                'managing_kitchen_id' => $kitchen->id,
                'effective_from' => $boundary,
            ]);
        });
    }

    public function establishSupply(Location $restaurant, Location $kitchen): LocationSupplyRelationship
    {
        return DB::transaction(function () use ($restaurant, $kitchen): LocationSupplyRelationship {
            $organization = $this->lockOrganization();
            $restaurant = Location::findOrFail($restaurant->id);
            $kitchen = Location::findOrFail($kitchen->id);
            $this->validatePair($organization, $restaurant, $kitchen);
            if ($restaurant->supplyRelationships()->where('supplier_kitchen_id', $kitchen->id)->where('is_active', true)->exists()) {
                throw new BusinessRuleViolation('This supply relationship is already active.');
            }

            return LocationSupplyRelationship::create([
                'organization_id' => $organization->id,
                'restaurant_id' => $restaurant->id,
                'supplier_kitchen_id' => $kitchen->id,
                'is_active' => true,
            ]);
        });
    }

    public function disableSupply(LocationSupplyRelationship $relationship): LocationSupplyRelationship
    {
        return DB::transaction(function () use ($relationship): LocationSupplyRelationship {
            $organization = $this->lockOrganization();
            $relationship = LocationSupplyRelationship::findOrFail($relationship->id);
            if ($relationship->organization_id !== $organization->id) {
                throw new BusinessRuleViolation('The supply relationship belongs to another organization.');
            }
            $relationship->update(['is_active' => false]);

            return $relationship->refresh();
        });
    }

    public function managedRestaurants(Location $kitchen, int $perPage): LengthAwarePaginator
    {
        $this->requireType($kitchen, LocationType::CentralKitchen);

        return Location::whereHas('managementAssignments', fn (Builder $query) => $query->current()->where('managing_kitchen_id', $kitchen->id))
            ->orderBy('code')->paginate($perPage);
    }

    public function currentManagingKitchen(Location $restaurant): ?Location
    {
        $this->requireType($restaurant, LocationType::Restaurant);

        return $restaurant->managementAssignments()->current()->with('managingKitchen')->first()?->managingKitchen;
    }

    public function authorizedSuppliers(Location $restaurant, int $perPage): LengthAwarePaginator
    {
        $this->requireType($restaurant, LocationType::Restaurant);

        return Location::whereHas('suppliedRelationships', fn (Builder $query) => $query->where('restaurant_id', $restaurant->id)->where('is_active', true))
            ->orderBy('code')->paginate($perPage);
    }

    public function managementHistory(Location $restaurant, int $perPage): LengthAwarePaginator
    {
        $this->requireType($restaurant, LocationType::Restaurant);

        return $restaurant->managementAssignments()->orderByDesc('effective_from')->paginate($perPage);
    }

    public function supplyRelationships(Location $restaurant, int $perPage): LengthAwarePaginator
    {
        $this->requireType($restaurant, LocationType::Restaurant);

        return $restaurant->supplyRelationships()->orderBy('created_at')->orderBy('id')->paginate($perPage);
    }

    private function lockOrganization(): Organization
    {
        $organization = Organization::lockForUpdate()->first();
        if (! $organization) {
            throw new BusinessRuleViolation('Initialize the installation organization first.');
        }

        return $organization;
    }

    private function validatePair(Organization $organization, Location $restaurant, Location $kitchen): void
    {
        $this->ensureOwned($organization, $restaurant);
        $this->ensureOwned($organization, $kitchen);
        $this->requireType($restaurant, LocationType::Restaurant);
        $this->requireType($kitchen, LocationType::CentralKitchen);
    }

    private function ensureOwned(Organization $organization, Location $location): void
    {
        if ($location->organization_id !== $organization->id) {
            throw new BusinessRuleViolation('Locations must belong to the installation organization.');
        }
    }

    private function requireType(Location $location, LocationType $type): void
    {
        if ($location->type !== $type) {
            throw new BusinessRuleViolation("Location must have type {$type->value}.");
        }
    }

    private function ensureCodeAvailable(Organization $organization, string $code, ?Location $except = null): void
    {
        if ($organization->locations()->where('code', $code)->when($except, fn (Builder $query) => $query->whereKeyNot($except->id))->exists()) {
            throw ValidationException::withMessages(['code' => ['This location code is already in use.']]);
        }
    }
}
