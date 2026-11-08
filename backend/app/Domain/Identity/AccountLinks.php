<?php

namespace App\Domain\Identity;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\SecurityMutation;
use App\Domain\Installation\BusinessRuleViolation;
use App\Mail\IdentityLink;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AccountLinks
{
    public function __construct(
        private BusinessAuthorization $authorization,
        private SecurityMutation $mutations,
        private AuthorizationMutationContext $contexts,
    ) {}

    private function repository(string $kind): DatabaseTokenRepository
    {
        if (! in_array($kind, ['activation', 'reset'], true)) {
            throw new \InvalidArgumentException('Unsupported identity link type.');
        }

        return new DatabaseTokenRepository(DB::connection(), app('hash'), $kind === 'activation' ? 'activation_tokens' : 'password_reset_tokens', config('app.key'), $kind === 'activation' ? 86400 : 1800, 0);
    }

    public function canSend(User $actor, Location $context, User $target): bool
    {
        if (! $this->authorization->eligible($actor, $context) || $actor->organization_id !== $target->organization_id) {
            return false;
        }
        if ($this->authorization->hasRole($actor, BusinessRole::Administrator)) {
            return true;
        }
        $roles = RoleAssignment::effective()->where('user_id', $target->id)->get();
        if ($roles->isEmpty()) {
            return false;
        }
        foreach ($roles as $role) {
            if ($role->role->organizationWide() || $role->role === BusinessRole::CentralKitchenChef) {
                return false;
            }
            $location = Location::findOrFail($role->location_id);
            if ($this->authorization->manages($actor, $context, $location)) {
                continue;
            }
            if (! $context->is($location) || $role->role === BusinessRole::RestaurantManager
                || ! $this->authorization->hasRole($actor, BusinessRole::RestaurantManager, $context)) {
                return false;
            }
        }

        return true;
    }

    public function issue(
        User $target,
        string $kind,
        string $ip,
        ?User $actor = null,
        ?Location $context = null,
        bool $bootstrap = false,
        bool $selfProfileVerification = false,
    ): void {
        $repository = $this->repository($kind);
        $delivery = $this->contexts->run(AuthorizationMutationScope::accountIssue(), $actor?->id, fn (): ?array => DB::transaction(function () use ($target, $kind, $ip, $actor, $context, $bootstrap, $selfProfileVerification, $repository): ?array {
            $organization = Organization::whereKey($target->organization_id)->lockForUpdate()->firstOrFail();
            $target = $target->fresh();
            $allowed = $bootstrap
                ? ! $organization->authorization_initialized && $target->is_initial_identity && $actor === null && $kind === 'activation'
                : ($selfProfileVerification
                    ? $this->canIssueSelfProfileVerification($actor, $context, $target, $organization, $kind)
                    : $actor && $context && $this->canSend($actor, $context, $target));
            if (! $allowed || ! $target->is_active || ($kind === 'reset' && $target->email_verified_at === null)
                || ($kind === 'activation' && $target->email_verified_at !== null)) {
                throw new AuthorizationException;
            }
            if (! app()->environment('testing') && config('mail.default') !== 'smtp') {
                throw new BusinessRuleViolation(__('messages.identity_links_smtp_required'));
            }
            $ipHash = hash('sha256', $ip);
            $sent = DB::table('account_email_deliveries')->where('user_id', $target->id)->where('sent_at', '>', now()->subHour());
            if ((clone $sent)->count() >= 3 || (clone $sent)->where('sent_at', '>', now()->subMinute())->exists()
                || DB::table('account_email_deliveries')->where('ip_hash', $ipHash)->where('sent_at', '>', now()->subHour())->count() >= 20) {
                $this->mutations->record($actor, $target, $context, 'email.rate_limit', 'rejected');

                return null;
            }
            $token = $repository->create($target);
            DB::table('account_email_deliveries')->insert([
                'id' => Str::uuid()->toString(), 'organization_id' => $target->organization_id, 'user_id' => $target->id,
                'kind' => $kind, 'ip_hash' => $ipHash, 'sent_at' => now(),
            ]);
            $this->mutations->record($actor, $target, $context, $kind.'.initiate', 'accepted');

            return ['email' => $target->email, 'token' => $token];
        }));
        if ($delivery === null) {
            throw new BusinessRuleViolation(__('messages.identity_rate_limited'));
        }
        // Synchronous delivery: no plaintext token is serialized into a queue or application log.
        try {
            Mail::to($delivery['email'])->send(new IdentityLink($kind, $delivery['email'], $delivery['token']));
        } catch (TransportExceptionInterface) {
            Log::error('Identity email SMTP delivery failed; retry requires a new link.');
            $this->mutations->record($actor, $target, $context, 'email.delivery', 'rejected');
            throw new BusinessRuleViolation(__('messages.identity_smtp_delivery_failed'));
        }
        unset($delivery);
    }

    private function canIssueSelfProfileVerification(
        ?User $actor,
        ?Location $context,
        User $target,
        Organization $organization,
        string $kind,
    ): bool {
        $primary = $organization->primaryCentralKitchen()->first();

        return $actor !== null && $context !== null && $actor->is($target)
            && $target->is_initial_identity && $target->is_active && $target->email_verified_at === null
            && $kind === 'activation' && $organization->is_active && $organization->authorization_initialized
            && ! $organization->primary_central_kitchen_unresolved
            && $primary !== null && $primary->is_active
            && $context->organization_id === $organization->id && $context->is_active
            && RoleAssignment::where('organization_id', $organization->id)->where('user_id', $target->id)
                ->where('role', BusinessRole::Administrator)->whereNull('revoked_at')->exists()
            && app(MembershipService::class)->available($target)->where('location_id', $primary->id)->exists()
            && app(MembershipService::class)->available($target)->where('location_id', $context->id)->exists();
    }

    public function consume(string $kind, string $email, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password): void
    {
        $repository = $this->repository($kind);
        Validator::make(['password' => $password], ['password' => [
            'required', 'string', Password::min(12)->mixedCase()->numbers()->symbols(),
            fn ($attribute, $value, $fail) => strlen($value) > 72 ? $fail(__('messages.password_must_not_exceed_72_bytes')) : null,
        ]])->validate();
        $email = User::normalizeEmail($email);
        $consumed = $this->contexts->run(AuthorizationMutationScope::accountConsume(), null, fn (): bool => DB::transaction(function () use ($kind, $email, $token, $password, $repository): bool {
            Organization::lockForUpdate()->firstOrFail();
            $target = User::where('email', $email)->first();
            if (! $target || ! $target->is_active || ! $target->organization->is_active
                || ($kind === 'activation' && $target->email_verified_at !== null)
                || ($kind === 'reset' && $target->email_verified_at === null)
                || ! $repository->exists($target, $token)) {
                if ($target) {
                    $this->mutations->record(null, $target, null, $kind.'.complete', 'rejected');
                }

                return false;
            }
            $target->forceFill(['password' => Hash::make($password), 'email_verified_at' => $target->email_verified_at ?? now()])->save();
            $repository->delete($target);
            if ($kind === 'activation' && RoleAssignment::where('user_id', $target->id)->where('role', BusinessRole::Administrator)->whereNull('revoked_at')->exists()) {
                $target->organization->forceFill(['authorization_initialized' => true])->save();
            }
            $this->mutations->record(null, $target, null, $kind.'.complete', 'accepted');

            return true;
        }));
        if (! $consumed) {
            throw ValidationException::withMessages(['link' => __('messages.account_link_invalid')]);
        }
    }
}
