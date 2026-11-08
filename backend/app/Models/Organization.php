<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    use HasUuids;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'code', 'is_active'];

    protected $hidden = ['installation', 'authorization_initialized', 'primary_central_kitchen_unresolved'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'authorization_initialized' => 'boolean',
            'primary_central_kitchen_unresolved' => 'boolean',
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function primaryCentralKitchen(): HasOne
    {
        return $this->hasOne(Location::class)->where('is_primary_central_kitchen', true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
