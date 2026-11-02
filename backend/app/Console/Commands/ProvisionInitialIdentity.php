<?php

namespace App\Console\Commands;

use App\Domain\Identity\IdentityService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\ArgvInput;

class ProvisionInitialIdentity extends Command
{
    protected $signature = 'veltra:provision-initial-identity';

    protected $description = 'Interactively provision the initial identity; grants no roles or memberships';

    public function handle(IdentityService $service): int
    {
        if (! $this->input->isInteractive()
            || ($this->input instanceof ArgvInput && ! stream_isatty(STDIN))) {
            $this->error('An interactive terminal is required. Password arguments and non-interactive input are not supported.');

            return self::FAILURE;
        }
        if (User::exists()) {
            $this->error('Initial identity provisioning is already complete.');

            return self::FAILURE;
        }
        $profile = [
            'first_name' => $this->ask('First name'),
            'last_name' => $this->ask('Last name'),
            'email' => $this->ask('Email'),
        ];
        $password = $this->secret('Password (12+ characters, mixed case, number, symbol; max 72 bytes)', false);
        $confirmation = $this->secret('Confirm password', false);
        if (! is_string($password) || $password !== $confirmation) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }
        try {
            $service->provisionInitial($profile, $password);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } catch (BusinessRuleViolation $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException $exception) {
            report($exception);
            $this->error('Provisioning failed due to a database error. No credentials were printed.');

            return self::FAILURE;
        } finally {
            unset($password, $confirmation);
        }
        $this->info('Initial identity created. No roles, memberships or business permissions were granted.');

        return self::SUCCESS;
    }
}
