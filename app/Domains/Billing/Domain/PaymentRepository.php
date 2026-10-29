<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\Payment;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Payment aggregate (IC §9; ADR-191 B5-B). The FIRST
 * repository parameter of the payment commands — the bus resolves
 * `PaymentPolicy` from it.
 */
interface PaymentRepository extends Repository
{
    public function getById(string $id): Payment;

    public function getByIdForUpdate(string $id): Payment;

    /** The `RecordPayment` idempotency pre-check (in addition to the bus key). */
    public function exists(string $id): bool;
}
