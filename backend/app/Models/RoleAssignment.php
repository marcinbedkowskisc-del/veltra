<?php

namespace App\Models;

use App\Domain\Authorization\BusinessRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RoleAssignment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['organization_id', 'user_id', 'role', 'location_id', 'membership_id', 'granted_by', 'granted_at', 'revoked_by', 'revoked_at'];

    protected function casts(): array
    {
        return ['role' => BusinessRole::class, 'granted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function scopeEffective(Builder $query): void
    {
        $query->whereNull('revoked_at')->where(function (Builder $scope): void {
            $scope->whereNull('membership_id')->orWhereExists(function ($memberships): void {
                $memberships->selectRaw('1')->from('location_memberships as m')
                    ->join('locations as l', 'l.id', '=', 'm.location_id')
                    ->whereColumn('m.id', 'role_assignments.membership_id')
                    ->where('m.is_active', true)->where('l.is_active', true);
            });
        });
    }
}
