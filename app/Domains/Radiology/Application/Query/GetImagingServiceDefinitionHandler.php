<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\ImagingServiceSummary;
use App\Domains\Radiology\Domain\ImagingServiceReader;

/**
 * Orchestrates `GetImagingServiceDefinition` (IC §6.1 / §6.3; ADR-165 D-H).
 */
final class GetImagingServiceDefinitionHandler
{
    public function __construct(private readonly ImagingServiceReader $reader) {}

    public function __invoke(GetImagingServiceDefinition $query): ImagingServiceSummary
    {
        return $this->reader->summary($query->serviceId);
    }
}
