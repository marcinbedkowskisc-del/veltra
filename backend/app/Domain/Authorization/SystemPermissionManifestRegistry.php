<?php

namespace App\Domain\Authorization;

class SystemPermissionManifestRegistry
{
    public function find(string $id, int $version): ?SystemPermissionManifest
    {
        $manifest = match ($version) {
            1 => SystemPermissionManifest::stage6a1RawUpgrade(),
            2 => SystemPermissionManifest::stage6a1RawAcceptanceRecovery(),
            default => null,
        };

        return $manifest?->id === $id ? $manifest : null;
    }
}
