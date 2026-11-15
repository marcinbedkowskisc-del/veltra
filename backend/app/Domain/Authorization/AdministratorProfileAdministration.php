<?php

namespace App\Domain\Authorization;

use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class AdministratorProfileAdministration
{
    public function __construct(
        private BusinessAuthorization $authorization,
        private SecurityMutation $mutations,
    ) {}

    /**
     * @param  array{first_name?: string, last_name?: string, email?: string}  $profile
     */
    public function update(
        User $actor,
        Location $context,
        array $profile,
        #[SensitiveParameter] string $password,
    ): bool {
        $profile = array_intersect_key($profile, array_flip(['first_name', 'last_name', 'email']));
        if (isset($profile['email'])) {
            $profile['email'] = User::normalizeEmail($profile['email']);
        }
        validator($profile, [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($actor->id)],
        ])->validate();
        if ($profile === []) {
            throw ValidationException::withMessages([
                'profile' => [__('messages.profile_field_required')],
            ]);
        }

        return $this->mutations->run($actor, $actor, $context, 'account.profile_update', function () use (
            $actor, $context, $profile, $password,
        ): bool {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $this->authorization->eligible($user, $context)
                || ! $this->authorization->hasRole($user, BusinessRole::Administrator)) {
                throw new AuthorizationException(__('messages.unauthorized'));
            }

            $key = 'administrator-profile-confirm:'.$user->id.'|'.request()->ip();
            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw new AuthorizationException(__('messages.fresh_password_confirmation_rate_limited'));
            }
            RateLimiter::hit($key, 60);
            if (! Hash::check($password, $user->password)) {
                throw new AuthorizationException(__('messages.fresh_password_confirmation_required'));
            }
            RateLimiter::clear($key);

            $changes = [];
            foreach (['first_name', 'last_name'] as $name) {
                if (array_key_exists($name, $profile)) {
                    $changes[$name] = trim($profile[$name]);
                }
            }
            if (isset($profile['email'])) {
                $changes['email'] = $profile['email'];
            }

            $emailChanged = isset($changes['email']) && $changes['email'] !== $user->email;
            if ($emailChanged) {
                app(RoleAdministration::class)->requireOtherAdministrator($user);
            }
            $changes = array_filter($changes, fn (mixed $value, string $key): bool => $value !== $user->{$key}, ARRAY_FILTER_USE_BOTH);
            if ($changes !== []) {
                $user->fill($changes)->save();
            }

            return $emailChanged;
        });
    }
}
