<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\ImagingModalitySummary;
use App\Domains\Radiology\Domain\ImagingModalityReader;

/**
 * Orchestrates `GetImagingModalities` (IC §6.1 / §6.3; ADR-165 D-H).
 */
final class GetImagingModalitiesHandler
{
    public function __construct(private readonly ImagingModalityReader $reader) {}

    /**
     * @return list<ImagingModalitySummary>
     */
    public function __invoke(GetImagingModalities $query): array
    {
        return $this->reader->list($query->category, $query->active);
    }
}
