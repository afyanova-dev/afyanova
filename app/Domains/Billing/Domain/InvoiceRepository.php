<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\Invoice;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Invoice aggregate (IC §9; ADR-190 D-A). The FIRST
 * repository parameter of the invoice commands — the bus resolves
 * `InvoicePolicy` from it.
 */
interface InvoiceRepository extends Repository
{
    public function getById(string $id): Invoice;

    public function getByIdForUpdate(string $id): Invoice;
}
