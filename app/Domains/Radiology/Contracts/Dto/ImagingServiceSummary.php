<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one imaging service catalog entry
 * (ADR-165 D-H). Returned by `GetImagingServiceDefinition` / `GetImagingServices`
 * and consumed intra-domain by `CreateImagingOrderHandler` to snapshot the
 * code / name / version / modality code and inspect the orderability flags.
 * NOT an event payload.
 */
final readonly class ImagingServiceSummary
{
    public function __construct(
        public string $serviceId,
        public string $code,
        public string $name,
        public string $modalityId,
        public string $modalityCode,
        public ?string $bodySite,
        public ?string $defaultLaterality,
        public string $contrastRequirement,
        public ?string $preparationText,
        public ?int $durationMinutes,
        public ?int $turnaroundTargetMinutes,
        public ?string $requiredCapabilityCode,
        public bool $referralOnly,
        public string $availabilityStatus,
        public bool $active,
        public int $version,
    ) {}
}
