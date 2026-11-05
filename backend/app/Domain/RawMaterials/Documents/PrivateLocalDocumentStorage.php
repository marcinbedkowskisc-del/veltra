<?php

namespace App\Domain\RawMaterials\Documents;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class PrivateLocalDocumentStorage implements PrivateDocumentStorage
{
    private const DISK = 'raw_material_documents';

    public function put(string $key, string $contents): void
    {
        try {
            $stored = $this->disk()->put($key, $contents, ['visibility' => 'private']);
        } catch (Throwable $exception) {
            throw new DocumentStorageException(
                'RAW_MATERIAL_DOCUMENT_STORAGE_WRITE_FAILED',
                'Nie udało się bezpiecznie zapisać pliku dokumentu.',
                previous: $exception,
            );
        }

        if ($stored !== true) {
            throw new DocumentStorageException(
                'RAW_MATERIAL_DOCUMENT_STORAGE_WRITE_FAILED',
                'Nie udało się bezpiecznie zapisać pliku dokumentu.',
            );
        }
    }

    public function read(string $key): string
    {
        try {
            $disk = $this->disk();
            if (! $disk->exists($key)) {
                throw new DocumentStorageException(
                    'RAW_MATERIAL_DOCUMENT_PAYLOAD_MISSING',
                    'Nie znaleziono pliku dokumentu w prywatnym magazynie.',
                );
            }

            $contents = $disk->get($key);
        } catch (DocumentStorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DocumentStorageException(
                'RAW_MATERIAL_DOCUMENT_STORAGE_READ_FAILED',
                'Nie udało się odczytać pliku z prywatnego magazynu.',
                previous: $exception,
            );
        }

        if (! is_string($contents)) {
            throw new DocumentStorageException(
                'RAW_MATERIAL_DOCUMENT_STORAGE_READ_FAILED',
                'Nie udało się odczytać pliku z prywatnego magazynu.',
            );
        }

        return $contents;
    }

    public function delete(string $key): void
    {
        try {
            $deleted = $this->disk()->delete($key);
        } catch (Throwable $exception) {
            throw new DocumentStorageException(
                'RAW_MATERIAL_DOCUMENT_STORAGE_DELETE_FAILED',
                'Nie udało się usunąć niepowiązanego pliku z prywatnego magazynu.',
                previous: $exception,
            );
        }

        if ($deleted !== true) {
            throw new DocumentStorageException(
                'RAW_MATERIAL_DOCUMENT_STORAGE_DELETE_FAILED',
                'Nie udało się usunąć niepowiązanego pliku z prywatnego magazynu.',
            );
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
