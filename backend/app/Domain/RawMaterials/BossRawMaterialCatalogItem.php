<?php

namespace App\Domain\RawMaterials;

final readonly class BossRawMaterialCatalogItem
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $categoryId,
        public string $categoryName,
        public string $baseUnitCode,
        public ?array $supplierNames,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     code: string,
     *     name: string,
     *     category: array{id: string, name: string},
     *     base_unit_code: string,
     *     supplier_names: list<string>|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => [
                'id' => $this->categoryId,
                'name' => $this->categoryName,
            ],
            'base_unit_code' => $this->baseUnitCode,
            'supplier_names' => $this->supplierNames,
        ];
    }

    public static function fromDatabaseRow(object $row): self
    {
        $supplierNames = null;
        if (property_exists($row, 'supplier_snapshot_state')) {
            if ($row->supplier_snapshot_state === 'APPROVED_EMPTY') {
                $supplierNames = [];
            } elseif ($row->supplier_snapshot_state === 'APPROVED_NONEMPTY') {
                $decoded = property_exists($row, 'supplier_names_json') && $row->supplier_names_json !== null
                    ? (json_decode((string) $row->supplier_names_json, true, flags: JSON_THROW_ON_ERROR) ?: [])
                    : [];
                $supplierNames = array_values(array_unique(array_map(
                    static fn (mixed $name): string => (string) $name,
                    $decoded,
                )));
                sort($supplierNames, SORT_STRING);
                if ($supplierNames === []) {
                    throw new \LogicException('Approved supplier snapshot is missing supplier names.');
                }
            }
        }

        return new self(
            (string) $row->id,
            (string) $row->code,
            (string) $row->name,
            (string) $row->category_id,
            (string) $row->category_name,
            (string) $row->base_unit_code,
            $supplierNames,
        );
    }
}
