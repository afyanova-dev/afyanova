<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\CashSession;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Cash Session aggregate (IC §9; ADR-191 C6-A). The
 * FIRST repository parameter of the cash-session commands — the bus resolves
 * `CashSessionPolicy` from it.
 */
interface CashSessionRepository extends Repository
{
    public function getById(string $id): CashSession;

    public function getByIdForUpdate(string $id): CashSession;

    /** The live (OPEN | ACTIVE) session for a (cashier, facility), `lockForUpdate()`, or null. */
    public function liveForCashierAtFacilityForUpdate(string $cashierUserId, string $facilityId): ?CashSession;

    public function exists(string $id): bool;
}
