<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Contracts\Dto\ImagingModalitySummary;

/**
 * Read-side port for ImagingModality (IC §6.2 / §6.3; ADR-165 D-H). Returns
 * DTOs only.
 *
 * `summary` backs `GetImagingModality` (by-id) and is also the INTRA-domain
 * read `CreateImagingOrderHandler` uses to check a service's parent modality
 * operational status + capability. `list` backs `GetImagingModalities`.
 */
interface ImagingModalityReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the modality does not exist in the
     * current tenant scope (RLS).
     */
    public function summary(string $modalityId): ImagingModalitySummary;

    /**
     * The tenant's modality catalog, optionally filtered. NO pagination —
     * catalogs are small (the CP.1 / Lab.1 precedent).
     *
     * @return list<ImagingModalitySummary>
     */
    public function list(?string $category = null, ?bool $active = null): array;
}
