<?php

namespace App\Console\Commands;

use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialPermissionUpgrade;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Symfony\Component\Console\Input\ArgvInput;

class UpgradeRawMaterialPermissions extends Command
{
    protected $signature = 'veltra:upgrade-raw-material-permissions';

    protected $description = 'Jawne i audytowane nadanie wybranych nowych uprawnień istniejącej organizacji';

    public function handle(RawMaterialPermissionUpgrade $upgrade): int
    {
        if (! $this->input->isInteractive() || ($this->input instanceof ArgvInput && ! stream_isatty(STDIN))) {
            $this->error('Operacja wymaga interaktywnego terminala.');

            return self::FAILURE;
        }

        $actorId = trim((string) $this->ask('Identyfikator aktywnego administratora organizacji z prawem delegowania'));
        $recipientId = trim((string) $this->ask('Identyfikator odrębnego odbiorcy uprawnień'));
        $permissions = array_values(array_filter(array_map('trim', explode(',', (string) $this->ask(
            'Dokładna lista uprawnień do nadania, oddzielona przecinkami',
        )))));
        $reason = trim((string) $this->ask('Uzasadnienie administracyjne'));
        $idempotencyKey = trim((string) $this->ask('Klucz idempotencji operacji (8–120 znaków)'));
        $password = $this->secret('Potwierdź hasło administratora', false);
        if (! is_string($password)) {
            $this->error('Nie podano hasła administratora.');

            return self::FAILURE;
        }

        try {
            $result = $upgrade->grant(
                $actorId,
                $recipientId,
                $permissions,
                $reason,
                $idempotencyKey,
                $password,
            );
        } catch (AuthorizationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (RawMaterialException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException $exception) {
            report($exception);
            $this->error('Nie udało się zarejestrować nadania uprawnień. Operacja została wycofana.');

            return self::FAILURE;
        } finally {
            unset($password);
        }

        $this->info($result['replayed']
            ? 'Potwierdzono ponowienie wcześniej zakończonej operacji; nie utworzono nowych grantów.'
            : 'Wybrane uprawnienia nadano i zapisano w niezmiennym audycie.');
        $this->line('Liczba grantów w operacji: '.count($result['grants']));
        $this->warn('Operacja nie zmienia gotowości ani aktywacji modułu RAW_MATERIALS.');

        return self::SUCCESS;
    }
}
