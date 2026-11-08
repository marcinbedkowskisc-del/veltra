<?php

namespace App\Models;

use App\Domain\Installation\LocationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'name', 'code', 'type', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_active' => 'boolean',
            'is_primary_central_kitchen' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function managementAssignments(): HasMany
    {
        return $this->hasMany(LocationManagementAssignment::class, 'restaurant_id');
    }

    public function managedAssignments(): HasMany
    {
        return $this->hasMany(LocationManagementAssignment::class, 'managing_kitchen_id');
    }

    public function supplyRelationships(): HasMany
    {
        return $this->hasMany(LocationSupplyRelationship::class, 'restaurant_id');
    }

    public function suppliedRelationships(): HasMany
    {
        return $this->hasMany(LocationSupplyRelationship::class, 'supplier_kitchen_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(LocationMembership::class);
    }
}
