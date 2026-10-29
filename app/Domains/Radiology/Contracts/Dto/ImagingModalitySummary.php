<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one imaging modality catalog entry
 * (ADR-165 D-H). Returned by `GetImagingModality` / `GetImagingModalities` and
 * consumed intra-domain by `CreateImagingOrderHandler`. NOT an event payload.
 */
final readonly class ImagingModalitySummary
{
    public function __construct(
        public string $modalityId,
        public string $code,
        public string $name,
        public ?string $category,
        public ?string $description,
        public ?string $requiredCapabilityCode,
        public string $operationalStatus,
        public bool $active,
        public int $version,
    ) {}
}
