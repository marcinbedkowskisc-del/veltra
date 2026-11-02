<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationSupplyRelationship extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'supplier_kitchen_id', 'restaurant_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'restaurant_id');
    }

    public function supplierKitchen(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'supplier_kitchen_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
