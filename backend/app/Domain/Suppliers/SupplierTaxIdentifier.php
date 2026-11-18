<?php

namespace App\Domain\Suppliers;

use InvalidArgumentException;
use LogicException;

final class SupplierTaxIdentifier
{
    public static function normalize(string $identifier): string
    {
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper(trim($identifier), 'UTF-8'));
        if (! is_string($normalized)) {
            throw new InvalidArgumentException('Nie można znormalizować identyfikatora podatkowego.');
        }

        return $normalized;
    }

    public static function fingerprint(string $identifier): string
    {
        $normalized = self::normalize($identifier);
        $key = config('app.key');
        if ($normalized === '' || ! is_string($key) || $key === '') {
            throw new LogicException('A non-empty identifier and application key are required.');
        }

        return hash_hmac('sha256', $normalized, $key);
    }
}
