<?php

namespace App\Domain\RawMaterials\Documents;

final readonly class DocumentScanOutcome
{
    private function __construct(
        public DocumentScanStatus $status,
        public ?string $scannerVersion,
        public ?string $signatureVersion,
        public ?string $detectionName,
    ) {}

    public static function clean(string $scannerVersion, string $signatureVersion): self
    {
        return new self(DocumentScanStatus::Clean, $scannerVersion, $signatureVersion, null);
    }

    public static function infected(
        string $scannerVersion,
        string $signatureVersion,
        string $detectionName,
    ): self {
        return new self(DocumentScanStatus::Infected, $scannerVersion, $signatureVersion, $detectionName);
    }
}
