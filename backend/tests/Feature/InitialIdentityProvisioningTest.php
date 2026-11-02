<?php

namespace Tests\Feature;

use App\Console\Commands\ProvisionInitialIdentity;
use App\Domain\Identity\IdentityService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\IdentityTestCase;

class InitialIdentityProvisioningTest extends IdentityTestCase
{
    public function test_interactive_provisioning_uses_hidden_password_and_grants_no_memberships_or_permissions(): void
    {
        $command = app(ProvisionInitialIdentity::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->setInputs(['Initial', 'Identity', ' INITIAL@EXAMPLE.TEST ', self::PASSWORD, self::PASSWORD]);
        $this->assertSame(0, $tester->execute([], ['interactive' => true]));
        $this->assertStringContainsString('No roles, memberships or business permissions were granted.', $tester->getDisplay());
        $this->assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
        $user = User::firstOrFail();
        $this->assertSame('initial@example.test', $user->email);
        $this->assertTrue($user->is_initial_identity);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertSame(0, $user->memberships()->count());
        $this->assertSame(1, $tester->execute([], ['interactive' => true]));
        $this->assertStringContainsString('Initial identity provisioning is already complete.', $tester->getDisplay());
        $this->assertSame(1, User::count());
    }

    public function test_noninteractive_provisioning_is_rejected_without_creating_an_account(): void
    {
        $this->artisan('veltra:provision-initial-identity', ['--no-interaction' => true])
            ->expectsOutput('An interactive terminal is required. Password arguments and non-interactive input are not supported.')
            ->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_service_cannot_provision_a_second_identity(): void
    {
        $service = app(IdentityService::class);
        $profile = ['first_name' => 'Initial', 'last_name' => 'Identity', 'email' => 'initial@example.test'];
        $service->provisionInitial($profile, self::PASSWORD);
        $this->expectException(BusinessRuleViolation::class);
        $service->provisionInitial([...$profile, 'email' => 'second@example.test'], self::PASSWORD);
    }

    public function test_provisioning_rejects_weak_passwords_and_mismatched_confirmation(): void
    {
        try {
            app(IdentityService::class)->provisionInitial([
                'first_name' => 'Initial', 'last_name' => 'Identity', 'email' => 'initial@example.test',
            ], 'password');
            $this->fail('Weak password accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }
        $command = app(ProvisionInitialIdentity::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->setInputs(['Initial', 'Identity', 'initial@example.test', self::PASSWORD, 'different']);
        $this->assertSame(1, $tester->execute([], ['interactive' => true]));
        $this->assertStringContainsString('Password confirmation does not match.', $tester->getDisplay());
        $this->assertSame(0, User::count());
    }
}
