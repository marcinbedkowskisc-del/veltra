<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationManagementAssignment extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['organization_id', 'restaurant_id', 'managing_kitchen_id', 'effective_from', 'effective_until'];

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_datetime', 'effective_until' => 'immutable_datetime'];
    }

    public function scopeCurrent(Builder $query): void
    {
        $query->whereRaw('effective_from <= statement_timestamp()')
            ->where(fn (Builder $period) => $period->whereNull('effective_until')->orWhereRaw('effective_until > statement_timestamp()'));
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'restaurant_id');
    }

    public function managingKitchen(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'managing_kitchen_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
