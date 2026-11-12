<?php

namespace App\Console\Commands;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\SecurityMutation;
use App\Domain\Identity\AccountLinks;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocalStage6a1Instance;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArgvInput;

class RecoverStage6a1Identity extends Command
{
    protected $signature = 'veltra:recover-stage6a1-identity
        {--check : Sprawdź warunki bez wysyłania wiadomości i bez zapisu}';

    protected $description = 'Odzyskaj aktywację wyłącznie nieukończonego lokalnego bootstrapu Stage 6A.1';

    public function handle(AccountLinks $links, SecurityMutation $audit): int
    {
        if (! $this->option('check') && (! $this->input->isInteractive()
            || ($this->input instanceof ArgvInput && ! stream_isatty(STDIN)))) {
            $this->error('Wymagany jest interaktywny terminal właściciela.');

            return self::FAILURE;
        }

        $target = null;
        try {
            $identity = $this->assertInstance();
            $this->assertPendingBootstrap($identity);
            $this->info('Instancja: veltra-stage6a1; baza: veltra_stage6a1; API: localhost:8001; Mailpit: localhost:8026.');
            $this->line('Operacja zastąpi link aktywacyjny. Nie zmieni hasła, kont, przypisań ani gotowości modułów.');
            if ($this->option('check')) {
                $this->info('Warunki spełnione. Nie wykonano żadnego zapisu ani wysyłki.');

                return self::SUCCESS;
            }
            if ($this->ask('Aby potwierdzić, wpisz dokładnie ODZYSKAJ STAGE6A1') !== 'ODZYSKAJ STAGE6A1') {
                $this->error('Anulowano. Nie wykonano żadnego zapisu ani wysyłki.');

                return self::FAILURE;
            }

            DB::transaction(function () use ($links, $audit, &$target): void {
                $identity = $this->assertInstance();
                Organization::whereKey($identity['organization_id'])->lockForUpdate()->firstOrFail();
                $target = $this->assertPendingBootstrap($identity);
                $audit->record(null, $target, null, 'initial_identity.local_recovery', 'accepted');
                // Keep the state check, token replacement and recovery audit in one transaction.
                $links->issue($target, 'activation', 'cli-stage6a1-recovery', bootstrap: true);
            });
        } catch (BusinessRuleViolation|AuthorizationException $exception) {
            if ($target !== null) {
                $audit->record(null, $target, null, 'initial_identity.local_recovery', 'rejected');
            }
            $this->error($exception instanceof BusinessRuleViolation
                ? $exception->getMessage()
                : 'Odmowa odzyskania: stan bootstrapu nie pozwala na wysłanie aktywacji.');

            return self::FAILURE;
        } catch (QueryException $exception) {
            report($exception);
            $this->error('Błąd bazy danych. Nie potwierdzono odzyskania; sprawdź stan przed ponowieniem.');

            return self::FAILURE;
        }

        $this->info('Wysłano nową aktywację do lokalnego Mailpit. Właściciel samodzielnie ustawia hasło.');

        return self::SUCCESS;
    }

    protected function assertInstance(): array
    {
        if (PHP_SAPI !== 'cli' || ! app()->runningInConsole() || ! app()->environment('local')
            || ! $this->insideContainer()) {
            throw new BusinessRuleViolation('Odmowa: odzyskanie jest dostępne tylko w lokalnym kontenerze CLI.');
        }
        $identity = $this->readIdentity();
        $instance = app(LocalStage6a1Instance::class);
        $instance->assertConfiguration($identity, $this->insideContainer());
        $instance->assertDatabase($identity, $this->databaseIdentity());

        return $identity;
    }

    protected function insideContainer(): bool
    {
        return is_file('/.dockerenv');
    }

    protected function readIdentity(): array
    {
        return app(LocalStage6a1Instance::class)->readIdentity('/run/veltra-stage6a1-recovery.json');
    }

    protected function databaseIdentity(): object
    {
        return app(LocalStage6a1Instance::class)->databaseIdentity();
    }

    private function assertPendingBootstrap(array $identity): User
    {
        if (Organization::count() !== 1 || User::count() !== 1
            || User::where('is_initial_identity', true)->count() !== 1) {
            throw new BusinessRuleViolation('Odmowa: wymagana jest dokładnie jedna organizacja i jedna tożsamość początkowa, bez innych kont.');
        }
        $organization = Organization::find($identity['organization_id']);
        $user = User::find($identity['user_id']);
        if (! $organization || ! $organization->is_active || $organization->authorization_initialized
            || $organization->primary_central_kitchen_unresolved
            || ! $user || ! $user->is_initial_identity || ! $user->is_active
            || $user->email_verified_at !== null || $user->organization_id !== $organization->id) {
            throw new BusinessRuleViolation('Odmowa: instancja nie ma oczekującej tożsamości początkowej lub bootstrap został zakończony.');
        }
        $primary = $organization->primaryCentralKitchen()->first();
        if (! $primary || ! $primary->is_active || Location::count() !== 1
            || LocationMembership::count() !== 1 || RoleAssignment::count() !== 1
            || ! app(MembershipService::class)->available($user)->where('location_id', $primary->id)->exists()
            || ! RoleAssignment::where('user_id', $user->id)->where('organization_id', $organization->id)
                ->where('role', BusinessRole::Administrator)->whereNull('location_id')->whereNull('membership_id')
                ->whereNull('revoked_at')->exists()) {
            throw new BusinessRuleViolation('Odmowa: przypisania nie odpowiadają pojedynczemu, nieukończonemu bootstrapowi.');
        }
        foreach (['raw_materials', 'permission_grants', 'organization_permission_grants', 'report_shares', 'membership_requests'] as $table) {
            if (DB::table($table)->exists()) {
                throw new BusinessRuleViolation('Odmowa: wykryto dane lub uprawnienia wskazujące na rozpoczętą eksploatację.');
            }
        }
        if (DB::table('location_module_states')->where('enabled', true)->exists()
            || DB::table('security_events')->where('outcome', 'accepted')->whereNotIn('action', [
                'administrator.bootstrap', 'activation.initiate', 'initial_identity.local_recovery',
            ])->exists()) {
            throw new BusinessRuleViolation('Odmowa: wykryto aktywne moduły lub historię rozpoczętej eksploatacji.');
        }

        return $user;
    }
}
