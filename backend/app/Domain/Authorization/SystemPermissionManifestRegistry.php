<?php

namespace App\Domain\Authorization;

class SystemPermissionManifestRegistry
{
    public function find(string $id, int $version): ?SystemPermissionManifest
    {
        $manifest = SystemPermissionManifest::stage6a1RawUpgrade();

        return $manifest->id === $id && $manifest->version === $version ? $manifest : null;
    }
}
