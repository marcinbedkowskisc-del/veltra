<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MembershipRequest extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['organization_id', 'user_id', 'location_id', 'requested_by', 'decision', 'decided_by', 'decided_at'];
}
