<?php

namespace App\Console\Commands;

use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocalStage6a1Instance;
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

    protected $description = 'Konfiguracja RAW w jednej lokalizacji poświadczonej lokalnej instancji Stage 6A.1';

    public function handle(LocalStage6a1Instance $instance, ModuleConfigurationService $modules): int
    {
        if (! $this->input->isInteractive() || ($this->input instanceof ArgvInput && ! stream_isatty(STDIN))) {
            $this->error('Operacja wymaga interaktywnego terminala.');

            return self::FAILURE;
        }
        try {
            if (! $instance->rawIntegrationEnabled()) {
                throw new BusinessRuleViolation('Brak zweryfikowanego lokalnego opt-in RAW.');
            }
            $actor = User::where('email', 'developer@veltra.test')->where('is_initial_identity', true)->firstOrFail();
            $location = Location::where('organization_id', $actor->organization_id)
                ->where('code', 'VELTRA_KUCHNIA')->where('is_primary_central_kitchen', true)->firstOrFail();
            $this->line('Lokalna baza veltra_stage6a1; administrator developer@veltra.test; lokalizacja VELTRA_KUCHNIA.');
            $this->warn('Tylko lokalne testy RAW. Produkcyjny katalog pozostaje NOT_READY. Ta komenda nie nadaje uprawnień RAW.');
            if (! $this->confirm('Włączyć RAW w kuchni deweloperskiej?', false)) {
                return self::FAILURE;
            }
            $password = $this->secret('Potwierdź hasło administratora', false);
            if (! is_string($password) || ! $instance->rawIntegrationEnabled()) {
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
