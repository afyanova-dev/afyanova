<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Exception\IllegalInvoiceTransition;
use App\Domains\Billing\Domain\InvoiceLineRepository;
use App\Domains\Billing\Domain\InvoiceRepository;
use App\Domains\Billing\Domain\Money;

/**
 * Orchestrates `RemoveInvoiceLine` (ADR-190 D-C). DRAFT only. Deactivates the
 * live line (releases the charge — INV-3) and recomputes totals. No-op if there
 * is no live line for the charge on this invoice.
 */
final class RemoveInvoiceLineHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceLineRepository $lines,
    ) {}

    public function __invoke(RemoveInvoiceLine $command): void
    {
        $invoice = $this->invoices->getByIdForUpdate($command->invoiceId);

        if (! $invoice->isDraft()) {
            throw IllegalInvoiceTransition::fromStatus('RemoveInvoiceLine', (string) $invoice->status);
        }

        $line = $this->lines->liveLineForChargeOnInvoice($command->invoiceId, $command->chargeId);
        if ($line === null) {
            return;
        }

        $line->deactivate();
        $this->lines->save($line);

        $gross = '0';
        foreach ($this->lines->liveLinesForInvoiceForUpdate($command->invoiceId) as $liveLine) {
            $gross = Money::add($gross, (string) $liveLine->amount);
        }
        $invoice->recomputeTotals($gross);
        // The line set changed — any stored coverage split is now stale (ADR-201 D-C).
        $invoice->clearCoverage();
        $this->invoices->save($invoice);
    }
}
