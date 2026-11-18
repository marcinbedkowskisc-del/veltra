<?php

namespace App\Domain\Allergens;

final class EffectivePrecautionaryAllergenService
{
    public function __construct(
        private readonly InstallationPrecautionaryAllergenService $installationList,
    ) {}

    public function forDeclarations(
        array $directAllergenIds,
        array $materialPrecautionaryAllergenIds = [],
    ): array {
        return $this->compose(
            $this->installationList->allergenIds(),
            $directAllergenIds,
            $materialPrecautionaryAllergenIds,
        );
    }

    public function compose(
        array $installationAllergenIds,
        array $directAllergenIds,
        array $materialPrecautionaryAllergenIds = [],
    ): array {
        $direct = array_fill_keys($this->uniqueIds($directAllergenIds), true);
        $result = [];
        $included = [];

        // Canonical identities are compared exactly; parent and child scopes are not interchangeable.
        foreach (array_merge(
            $this->uniqueIds($materialPrecautionaryAllergenIds),
            $this->uniqueIds($installationAllergenIds),
        ) as $allergenId) {
            if (isset($direct[$allergenId]) || isset($included[$allergenId])) {
                continue;
            }

            $included[$allergenId] = true;
            $result[] = $allergenId;
        }

        return $result;
    }

    private function uniqueIds(array $allergenIds): array
    {
        $unique = [];
        foreach ($allergenIds as $allergenId) {
            if (is_string($allergenId) && $allergenId !== '') {
                $unique[$allergenId] = $allergenId;
            }
        }

        return array_values($unique);
    }
}
