<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain;

use App\Domains\Pharmacy\Domain\Model\Dispensing;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Dispensing aggregate (IC §9; ADR-184 D-B).
 *
 * `rx_dispensings` is append-only — a Dispensing is only ever INSERTed
 * ({@see save()}); `getByIdForUpdate` exists for interface completeness (the
 * `Dispense` command authorizes against the PharmacyOrder, not the Dispensing).
 *
 * Implementation: `Infrastructure/Repository/EloquentDispensingRepository`,
 * bound in `PharmacyServiceProvider`.
 */
interface DispensingRepository extends Repository
{
    public function getById(string $id): Dispensing;

    public function getByIdForUpdate(string $id): Dispensing;
}
