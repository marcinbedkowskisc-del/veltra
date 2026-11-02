<?php

namespace App\Models;

use App\Domain\Authorization\Permission;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PermissionGrant extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['organization_id', 'user_id', 'location_id', 'membership_id', 'permission', 'granted_by', 'valid_from', 'valid_until', 'revoked_at', 'revoked_by'];

    protected function casts(): array
    {
        return ['permission' => Permission::class, 'valid_from' => 'immutable_datetime', 'valid_until' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
