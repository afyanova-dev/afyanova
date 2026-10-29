<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain;

use App\Domains\Pharmacy\Domain\Model\DispensingReversal;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the DispensingReversal aggregate (IC §9; ADR-185 D-B).
 * `rx_dispensing_reversals` is append-only — a reversal is only ever INSERTed.
 *
 * Implementation: `Infrastructure/Repository/EloquentDispensingReversalRepository`,
 * bound in `PharmacyServiceProvider`.
 */
interface DispensingReversalRepository extends Repository
{
    public function getById(string $id): DispensingReversal;

    public function getByIdForUpdate(string $id): DispensingReversal;

    /** True if a reversal already exists for this dispensing (the `ReverseDispensing` pre-check). */
    public function existsForDispensing(string $dispensingId): bool;
}
