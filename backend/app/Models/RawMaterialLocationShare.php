<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RawMaterialLocationShare extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'raw_material_location_shares';

    protected $fillable = [
        'organization_id',
        'raw_material_id',
        'location_id',
        'created_by',
        'created_context_location_id',
        'created_operation_id',
        'revoked_by',
        'revoked_context_location_id',
        'revoked_operation_id',
        'revoked_at',
        'revocation_reason',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
