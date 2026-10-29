<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\ImagingModalitySummary;
use App\Domains\Radiology\Domain\ImagingModalityReader;

/**
 * Orchestrates `GetImagingModality` (IC §6.1 / §6.3; ADR-165 D-H). No
 * transaction, no audit, no event. The catalog is tenant-wide reference data
 * (RLS-scoped) — the `#[ProtectedQuery]` gate is the authorization (the CP.1 /
 * Lab.1 precedent).
 */
final class GetImagingModalityHandler
{
    public function __construct(private readonly ImagingModalityReader $reader) {}

    public function __invoke(GetImagingModality $query): ImagingModalitySummary
    {
        return $this->reader->summary($query->modalityId);
    }
}
