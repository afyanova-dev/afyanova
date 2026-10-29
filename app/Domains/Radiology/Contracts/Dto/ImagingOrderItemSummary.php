<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Dto;

/**
 * One requested-service line on an imaging order (ADR-165 D-H). A protected
 * read model, not an event payload.
 */
final readonly class ImagingOrderItemSummary
{
    public function __construct(
        public string $itemId,
        public string $imagingServiceId,
        public string $serviceCode,
        public string $serviceName,
        public int $serviceDefinitionVersion,
        public string $modalityCode,
        public ?string $bodySite,
        public ?string $laterality,
        public string $status,
    ) {}
}
