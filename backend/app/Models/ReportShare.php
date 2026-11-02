<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReportShare extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['organization_id', 'user_id', 'location_id', 'report_id', 'granted_by', 'revoked_at', 'revoked_by'];
}
