<?php

namespace App\Console\Commands;

use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocalStage6a1Instance;
use App\Domain\Installation\LocationType;
use App\Domain\Modules\ModuleConfigurationService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\ArgvInput;

class ConfigureStage6a1Raw extends Command
{
    protected $signature = 'veltra:configure-stage6a1-raw';

    protected $description = 'Konfiguracja RAW w jednej lokalizacji lokalnej instancji Stage 6A.1';

    public function handle(LocalStage6a1Instance $instance, ModuleConfigurationService $modules): int
    {
        if (! $this->input->isInteractive() || ($this->input instanceof ArgvInput && ! stream_isatty(STDIN))) {
            $this->error('Operacja wymaga interaktywnego terminala.');

            return self::FAILURE;
        }
        try {
            $identity = $instance->rawConfigurationIdentity();
            if ($identity === null) {
                throw new BusinessRuleViolation('Brak zweryfikowanego lokalnego opt-in RAW.');
            }
            $actor = User::whereKey($identity['user_id'])->where('organization_id', $identity['organization_id'])->firstOrFail();
            $locations = Location::where('organization_id', $identity['organization_id'])
                ->where('type', LocationType::CentralKitchen->value)
                ->where('is_active', true)->where('is_primary_central_kitchen', true)->limit(2)->get();
            if ($locations->count() !== 1) {
                throw new BusinessRuleViolation('Nie znaleziono jednej aktywnej głównej kuchni centralnej.');
            }
            $location = $locations->first();
            $this->line("Lokalna baza veltra_stage6a1; administrator {$actor->id}; kuchnia {$location->id}.");
            $this->warn('Tylko lokalne testy RAW. Produkcyjny katalog pozostaje NOT_READY. Ta komenda nie nadaje uprawnień RAW.');
            if (! $this->confirm('Włączyć RAW w kuchni deweloperskiej?', false)) {
                return self::FAILURE;
            }
            $password = $this->secret('Potwierdź hasło administratora', false);
            if (! is_string($password) || $instance->rawConfigurationIdentity() !== $identity) {
                throw new BusinessRuleViolation('Nie potwierdzono operacji i lokalnej instancji.');
            }
            $result = $modules->configure($actor, $location, [
                'module_ids' => ['RAW_MATERIALS'], 'enabled' => true,
                'location_ids' => [$location->id], 'current_password' => $password,
            ]);
            $this->info('Zapisano standardową konfigurację lokalizacji i audyt. Zmienione: '.$result['changed_count'].'.');

            return self::SUCCESS;
        } catch (BusinessRuleViolation|AuthorizationException|ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException $exception) {
            report($exception);
            $this->error('Nie zapisano konfiguracji RAW. Sprawdź audyt i połączenie z lokalną bazą.');

            return self::FAILURE;
        } finally {
            unset($password);
        }
    }
}
