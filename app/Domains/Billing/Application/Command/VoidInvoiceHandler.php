<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Exception\InvoiceHasActiveAllocations;
use App\Domains\Billing\Domain\InvoiceLineRepository;
use App\Domains\Billing\Domain\InvoiceRepository;
use App\Domains\Billing\Domain\PaymentAllocationReader;

/**
 * Orchestrates `VoidInvoice` (ADR-190 D-C; ADR-191 G-5). `getByIdForUpdate` →
 * REFUSE if the invoice has ≥ 1 active payment allocation
 * (`InvoiceHasActiveAllocations` — the additive amendment-by-pointer to
 * ADR-190) → deactivate every live line (releases the charges — INV-3) →
 * `Invoice::void` (asserts {DRAFT | ISSUED}; records `InvoiceVoided`). The
 * historical rows are preserved (INV-7).
 */
final class VoidInvoiceHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceLineRepository $lines,
        private readonly PaymentAllocationReader $allocations,
    ) {}

    public function __invoke(VoidInvoice $command): void
    {
        $invoice = $this->invoices->getByIdForUpdate($command->invoiceId);

        if ($this->allocations->invoiceHasActiveAllocation($command->invoiceId)) {
            throw InvoiceHasActiveAllocations::id($command->invoiceId);
        }

        foreach ($this->lines->liveLinesForInvoiceForUpdate($command->invoiceId) as $line) {
            $line->deactivate();
            $this->lines->save($line);
        }

        $invoice->void($command->actorId, $command->reasonCode);

        $this->invoices->save($invoice);
    }
}
