<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\ImagingServiceSummary;
use App\Domains\Radiology\Domain\ImagingServiceReader;

/**
 * Orchestrates `GetImagingServices` (IC §6.1 / §6.3; ADR-165 D-H).
 */
final class GetImagingServicesHandler
{
    public function __construct(private readonly ImagingServiceReader $reader) {}

    /**
     * @return list<ImagingServiceSummary>
     */
    public function __invoke(GetImagingServices $query): array
    {
        return $this->reader->list($query->modalityId, $query->active);
    }
}
