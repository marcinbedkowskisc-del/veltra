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
    ) {}

    /**
     * @return array{
     *     id: string,
     *     code: string,
     *     name: string,
     *     category: array{id: string, name: string},
     *     base_unit_code: string
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
        ];
    }

    public static function fromDatabaseRow(object $row): self
    {
        return new self(
            (string) $row->id,
            (string) $row->code,
            (string) $row->name,
            (string) $row->category_id,
            (string) $row->category_name,
            (string) $row->base_unit_code,
        );
    }
}
