<?php

namespace App\Domain\Authorization;

final readonly class BusinessVisibilityDecision
{
    private function __construct(
        public bool $allowed,
        public BusinessVisibilitySource $source,
        public string $reason,
    ) {}

    public static function allow(BusinessVisibilitySource $source): self
    {
        return new self(true, $source, 'AUTHORIZED');
    }

    public static function deny(BusinessVisibilitySource $source, string $reason): self
    {
        return new self(false, $source, $reason);
    }
}
