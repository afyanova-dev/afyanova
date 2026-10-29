<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain;

use App\Domains\Pharmacy\Domain\Model\Pickup;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Pickup aggregate (IC §9; ADR-186 D-B).
 *
 * `forDispensingForUpdate` is the shared serialisation lock between the pickup
 * commands and `ReverseDispensingHandler` (ADR-186 D-E).
 *
 * Implementation: `Infrastructure/Repository/EloquentPickupRepository`, bound in
 * `PharmacyServiceProvider`.
 */
interface PickupRepository extends Repository
{
    public function getById(string $id): Pickup;

    public function getByIdForUpdate(string $id): Pickup;

    /** The Pickup for one Dispensing, `lockForUpdate()`. Throws `NotFound` if absent. */
    public function forDispensingForUpdate(string $dispensingId): Pickup;

    /** The Pickup for one Dispensing, or null (no lock) — `ReverseDispensingHandler` uses the locked variant). */
    public function forDispensing(string $dispensingId): ?Pickup;
}
