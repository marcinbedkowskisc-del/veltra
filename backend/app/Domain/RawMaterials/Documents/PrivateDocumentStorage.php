<?php

namespace App\Domain\RawMaterials\Documents;

interface PrivateDocumentStorage
{
    public function put(string $key, string $contents): void;

    public function read(string $key): string;

    public function delete(string $key): void;
}
