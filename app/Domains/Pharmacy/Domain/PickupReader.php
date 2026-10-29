<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain;

use App\Domains\Pharmacy\Contracts\Dto\PickupSummary;

/**
 * Read-side port for the Pickup aggregate (IC §6.2 / §6.3; ADR-186 D-F).
 * Returns DTOs only, never an Eloquent model.
 */
interface PickupReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the current tenant scope (RLS). */
    public function summary(string $pickupId): PickupSummary;

    /**
     * The pickup queue at one facility; optional status filter; newest first.
     *
     * @return list<PickupSummary>
     */
    public function forFacility(string $facilityId, ?string $status): array;
}
