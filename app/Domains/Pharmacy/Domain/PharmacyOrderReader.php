<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain;

use App\Domains\Pharmacy\Contracts\Dto\PharmacyOrderSummary;

/**
 * Read-side port for the Pharmacy Order aggregate (IC §6.2 / §6.3; ADR-182 D-E).
 * Returns DTOs only, never an Eloquent model.
 */
interface PharmacyOrderReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the current tenant scope (RLS). */
    public function summary(string $pharmacyOrderId): PharmacyOrderSummary;

    /**
     * The pharmacy-order queue at one facility; optional status filter;
     * newest first.
     *
     * @return list<PharmacyOrderSummary>
     */
    public function forFacility(string $facilityId, ?string $status): array;
}
