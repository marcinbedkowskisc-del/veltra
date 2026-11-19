<?php

namespace App\Domain\Authorization;

use InvalidArgumentException;

final readonly class BusinessDataProvenance
{
    /** @param list<string> $locationIds */
    private function __construct(public BusinessDataScope $scope, public array $locationIds)
    {
        if ($scope === BusinessDataScope::Organization && $locationIds !== []
            || $scope === BusinessDataScope::Location && count($locationIds) !== 1
            || $scope === BusinessDataScope::Derived && $locationIds === []
            || $scope === BusinessDataScope::Unknown && $locationIds !== []) {
            throw new InvalidArgumentException('Business-data provenance does not match its scope.');
        }
        foreach ($locationIds as $id) {
            if (! is_string($id) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id)) {
                throw new InvalidArgumentException('Business-data provenance contains an invalid location identifier.');
            }
        }
        if (count($locationIds) !== count(array_unique(array_map('strtolower', $locationIds)))) {
            throw new InvalidArgumentException('Business-data provenance contains duplicate location identifiers.');
        }
    }

    public static function organization(): self
    {
        return new self(BusinessDataScope::Organization, []);
    }

    public static function location(string $locationId): self
    {
        return new self(BusinessDataScope::Location, [$locationId]);
    }

    /** @param non-empty-list<string> $locationIds */
    public static function derived(array $locationIds): self
    {
        return new self(BusinessDataScope::Derived, $locationIds);
    }

    public static function unknown(): self
    {
        return new self(BusinessDataScope::Unknown, []);
    }
}
