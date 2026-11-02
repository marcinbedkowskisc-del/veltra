<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationModuleState extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'location_id', 'module_key', 'enabled', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
