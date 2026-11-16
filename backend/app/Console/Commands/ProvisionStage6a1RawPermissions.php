<?php

namespace App\Console\Commands;

use App\Domain\Authorization\SystemPermissionManifestRegistry;
use App\Domain\Authorization\SystemPermissionProvisioner;
use App\Domain\Authorization\SystemPermissionProvisioningException;
use App\Domain\Installation\BusinessRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class ProvisionStage6a1RawPermissions extends Command
{
    protected $signature = 'veltra:provision-stage6a1-raw-permissions {--version=1} {--show-release} {--approve-release=}';

    protected $description = 'Jawny provisioning zatwierdzonego manifestu RAW dla izolowanej instalacji Stage 6A.1';

    public function handle(SystemPermissionProvisioner $provisioner, SystemPermissionManifestRegistry $registry): int
    {
        $id = 'stage6a1-raw-administrator';
        $versionOption = $this->option('version');
        if (! is_string($versionOption) || ! in_array($versionOption, ['1', '2'], true)) {
            $this->error('Nieznana wersja manifestu.');

            return self::FAILURE;
        }
        $version = (int) $versionOption;
        try {
            if ($this->option('show-release')) {
                if ($this->option('approve-release') !== null) {
                    $this->error('Nie można jednocześnie pokazać i wykonać wydania.');

                    return self::FAILURE;
                }
                $manifest = $registry->find($id, $version);
                if (! $manifest) {
                    throw new SystemPermissionProvisioningException('Nieznany manifest lub wersja.');
                }
                $this->line("Manifest: {$manifest->id}, wersja: {$manifest->version}, hash: {$manifest->contentHash()}.");
                $this->line("Instalacja: {$manifest->databaseName}, organizacja: {$manifest->organizationId}, administrator: {$manifest->administratorId}, lokalizacja: {$manifest->locationId}.");
                $this->line('Uprawnienia: '.implode(', ', array_map(fn ($permission) => $permission->value, $manifest->permissions)).'; zakres: '.$manifest->grantScope.'.');
                $this->line('Zatwierdzenie wydania: '.$provisioner->releaseDigest($id, $version));

                return self::SUCCESS;
            }
            $approval = $this->option('approve-release');
            $operation = $provisioner->execute($id, $version, is_string($approval) ? $approval : '');
            $this->info("Operacja provisioningu: {$operation}.");

            return self::SUCCESS;
        } catch (SystemPermissionProvisioningException|BusinessRuleViolation|AuthorizationException|ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException $exception) {
            report($exception);
            $this->error('Nie zapisano provisioningu. Sprawdź audyt i połączenie z bazą.');

            return self::FAILURE;
        }
    }
}
