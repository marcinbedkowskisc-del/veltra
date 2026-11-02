<?php

namespace App\Domain\Identity;

use App\Domain\Installation\BusinessRuleViolation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

class IdentityService
{
    public function provisionInitial(array $profile, #[SensitiveParameter] string $password): User
    {
        $profile['email'] = User::normalizeEmail($profile['email'] ?? '');
        Validator::make([...$profile, 'password' => $password], [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => [
                'required', 'string', Password::min(12)->mixedCase()->numbers()->symbols(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail(__('messages.password_must_not_exceed_72_bytes'));
                    }
                },
            ],
        ])->validate();

        return DB::transaction(function () use ($profile, $password): User {
            DB::select('SELECT pg_advisory_xact_lock(8612002)');
            $organization = Organization::lockForUpdate()->first();
            if (! $organization || ! $organization->is_active) {
                throw new BusinessRuleViolation(__('messages.identity_provisioning_organization_required'));
            }
            if (User::exists()) {
                throw new BusinessRuleViolation(__('messages.identity_initialization_complete'));
            }

            return User::create([
                'organization_id' => $organization->id,
                'first_name' => trim($profile['first_name']),
                'last_name' => trim($profile['last_name']),
                'email' => $profile['email'],
                'password' => $password,
                'is_active' => true,
                'is_initial_identity' => true,
            ]);
        });
    }

    public function disable(User $user): void
    {
        $user->update(['is_active' => false]);
    }
}
