<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\Receipt;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Receipt aggregate (IC §9; ADR-191 B5-C). Manipulated
 * only through the payment command handlers.
 */
interface ReceiptRepository extends Repository
{
    /** The receipt for a payment, `lockForUpdate()` — every RECORDED payment has one. */
    public function getByPaymentIdForUpdate(string $paymentId): Receipt;
}
