<?php

namespace App\Domain\Sku;

enum SkuPrefix: string
{
    case Raw = 'RAW';
    case Intermediate = 'INT';
    case Product = 'PRD';
    case Menu = 'MEN';

    public function format(int $sequence): string
    {
        if ($sequence < 1 || $sequence > ($this === self::Raw ? 999999 : 999999999)) {
            throw new \InvalidArgumentException('Numer SKU jest poza dozwolonym zakresem.');
        }

        return $this->value.'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    public function parse(string $sku): ?int
    {
        if (! preg_match('/\A'.$this->value.'-([0-9]{6,9})\z/', $sku, $matches)) {
            return null;
        }

        $sequence = (int) $matches[1];

        if ($sequence < 1 || ($this === self::Raw && $sequence > 999999)) {
            return null;
        }

        return $this->format($sequence) === $sku ? $sequence : null;
    }
}
