<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Contracts\Dto\ImagingServiceSummary;

/**
 * Read-side port for ImagingService (IC §6.2 / §6.3; ADR-165 D-H). Returns
 * DTOs only.
 *
 * `summary` backs `GetImagingServiceDefinition` (by-id) and is also the
 * INTRA-domain read `CreateImagingOrderHandler` uses to snapshot the code /
 * name / version / modality code and inspect the orderability flags. `list`
 * backs `GetImagingServices` (the catalog — a consumer needs it to populate an
 * order picker).
 */
interface ImagingServiceReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the service does not exist in the
     * current tenant scope (RLS).
     */
    public function summary(string $serviceId): ImagingServiceSummary;

    /**
     * The tenant's imaging-service catalog, optionally filtered.
     *
     * @return list<ImagingServiceSummary>
     */
    public function list(?string $modalityId = null, ?bool $active = null): array;
}
