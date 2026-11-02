<?php

namespace App\Console\Commands;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Authorization\SecurityMutation;
use App\Domain\Identity\AccountLinks;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\InstallationService;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\ArgvInput;

class BootstrapAuthorization extends Command
{
    protected $signature = 'veltra:bootstrap-authorization';

    protected $description = 'Bootstrap the initial administrator using hidden password confirmation and email verification';

    public function handle(): int
    {
        if (! $this->input->isInteractive() || ($this->input instanceof ArgvInput && ! stream_isatty(STDIN))) {
            $this->error('An interactive terminal is required.');

            return self::FAILURE;
        }
        if (Organization::where('authorization_initialized', true)->exists()) {
            $this->error('Authorization is already initialized. Use the authorized administration workflow.');

            return self::FAILURE;
        }
        $user = User::where('is_initial_identity', true)->first();
        if (! $user) {
            $this->error('Initialize the organization and run veltra:provision-initial-identity first.');

            return self::FAILURE;
        }
        $password = $this->secret('Confirm the initial identity password', false);
        if (! is_string($password) || ! Hash::check($password, $user->password)) {
            app(SecurityMutation::class)->record(null, $user, null, 'administrator.bootstrap', 'rejected');
            $this->error('Initial identity verification failed.');

            return self::FAILURE;
        }
        unset($password);
        $location = Location::where('type', 'CENTRAL_KITCHEN')->where('is_active', true)->orderBy('code')->first();
        $profile = null;
        if (! $location) {
            $profile = ['name' => $this->ask('Initial central kitchen name'), 'code' => $this->ask('Initial central kitchen code')];
        }
        try {
            if ($profile) {
                validator($profile, ['name' => ['required', 'string', 'max:200'], 'code' => ['required', 'regex:/^[A-Z0-9_-]+$/', 'max:50']])->validate();
            }
            DB::transaction(function () use ($user, &$location, $profile): void {
                $organization = Organization::whereKey($user->organization_id)->lockForUpdate()->firstOrFail();
                if ($organization->authorization_initialized || ! $user->fresh()->is_active || $user->fresh()->email_verified_at !== null) {
                    throw new BusinessRuleViolation(__('messages.initial_authorization_unavailable'));
                }
                if (! $location) {
                    $location = app(InstallationService::class)->createLocation([...$profile, 'type' => 'CENTRAL_KITCHEN']);
                }
                if (! app(MembershipService::class)->available($user)->where('location_id', $location->id)->exists()) {
                    app(MembershipService::class)->assign($user, $location);
                }
                if (! RoleAssignment::where('user_id', $user->id)->where('role', BusinessRole::Administrator)->exists()) {
                    app(RoleAdministration::class)->bootstrapInitial($user);
                }
            });
            app(AccountLinks::class)->issue($user, 'activation', 'cli-bootstrap', bootstrap: true);
        } catch (BusinessRuleViolation|AuthorizationException|ValidationException $exception) {
            app(SecurityMutation::class)->record(null, $user, $location, 'administrator.bootstrap', 'rejected');
            $this->error($exception instanceof ValidationException ? 'Invalid bootstrap input.' : $exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Initial administrator provisioned pending email verification. An activation email was sent; no credentials printed.');

        return self::SUCCESS;
    }
}
