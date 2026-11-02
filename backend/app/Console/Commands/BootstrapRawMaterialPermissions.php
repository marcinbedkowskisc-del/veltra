<?php

namespace App\Console\Commands;

use App\Domain\RawMaterials\RawMaterialPermissionBootstrap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Symfony\Component\Console\Input\ArgvInput;

class BootstrapRawMaterialPermissions extends Command
{
    protected $signature = 'veltra:bootstrap-raw-material-permissions';

    protected $description = 'Jawne i audytowane nadanie pierwszych uprawnień organizacyjnych do katalogu surowców';

    public function handle(RawMaterialPermissionBootstrap $bootstrap): int
    {
        if (! $this->input->isInteractive() || ($this->input instanceof ArgvInput && ! stream_isatty(STDIN))) {
            $this->error('Operacja wymaga interaktywnego terminala.');

            return self::FAILURE;
        }

        $actorId = trim((string) $this->ask('Identyfikator aktywnego administratora organizacji'));
        $recipientId = trim((string) $this->ask('Identyfikator odrębnego użytkownika, który zostanie pierwszym opiekunem'));
        $permissions = array_values(array_filter(array_map('trim', explode(',', (string) $this->ask(
            'Jawna lista uprawnień oddzielona przecinkami; wymaga RAW_MATERIALS_PERMISSION_DELEGATE',
        )))));
        $reason = trim((string) $this->ask('Powód nadania'));
        $password = $this->secret('Potwierdź hasło administratora', false);
        if (! is_string($password) || $reason === '') {
            $this->error('Wymagane są prawidłowe dane operacji.');

            return self::FAILURE;
        }

        try {
            $grants = $bootstrap->grantInitial($actorId, $recipientId, $permissions, $reason, $password);
        } catch (AuthorizationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException $exception) {
            report($exception);
            $this->error('Nie udało się zapisać pierwszych uprawnień. Operacja została wycofana.');

            return self::FAILURE;
        } finally {
            unset($password);
        }

        $this->info('Pierwsze uprawnienia nadano odrębnemu użytkownikowi i zapisano w niezmiennym audycie.');
        $this->line('Liczba nadanych uprawnień: '.count($grants));
        $this->warn('Moduł RAW_MATERIALS pozostaje NOT_READY i nie został aktywowany.');

        return self::SUCCESS;
    }
}
