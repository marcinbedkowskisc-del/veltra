<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasUuids;

    protected $fillable = ['organization_id', 'first_name', 'last_name', 'email', 'password', 'is_active', 'is_initial_identity'];

    protected $hidden = ['password', 'is_initial_identity', 'auth_version', 'email_verified_at'];

    protected $attributes = ['is_active' => true, 'is_initial_identity' => false];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'is_initial_identity' => 'boolean', 'email_verified_at' => 'immutable_datetime', 'auth_version' => 'integer'];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => self::normalizeEmail($value));
    }

    public static function normalizeEmail(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(LocationMembership::class);
    }
}
