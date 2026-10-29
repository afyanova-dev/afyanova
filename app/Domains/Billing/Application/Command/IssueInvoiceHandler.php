<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\ChargeReader;
use App\Domains\Billing\Domain\Exception\InvoiceHasReversedLine;
use App\Domains\Billing\Domain\Exception\NoChargesToInvoice;
use App\Domains\Billing\Domain\InvoiceLineRepository;
use App\Domains\Billing\Domain\InvoiceRepository;
use App\Domains\Billing\Domain\ValueObject\ChargeStatus;
use App\Domains\Billing\Infrastructure\Sequence\InvoiceNumberSequence;
use App\Shared\Tenancy\TenantContext;

/**
 * Orchestrates `IssueInvoice` (ADR-190 D-C). `getByIdForUpdate` → `Invoice::issue`
 * asserts DRAFT → ≥ 1 active line (`NoChargesToInvoice`) → no active line's
 * charge is REVERSED (`InvoiceHasReversedLine` — INV-4) → mint `INV-%08d` →
 * `issue()` (records `InvoiceIssued`, freezes `net`) → save.
 */
final class IssueInvoiceHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceLineRepository $lines,
        private readonly ChargeReader $charges,
        private readonly InvoiceNumberSequence $numbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(IssueInvoice $command): void
    {
        $invoice = $this->invoices->getByIdForUpdate($command->invoiceId);

        $liveLines = $this->lines->liveLinesForInvoiceForUpdate($command->invoiceId);
        if ($liveLines === []) {
            throw NoChargesToInvoice::emptyInvoice($command->invoiceId);
        }

        foreach ($liveLines as $line) {
            if ($this->charges->summary((string) $line->charge_id)->status === ChargeStatus::REVERSED) {
                throw InvoiceHasReversedLine::forCharge((string) $line->charge_id);
            }
        }

        $invoice->issue($this->numbers->next($this->context->tenantId()), $command->actorId);

        $this->invoices->save($invoice);
    }
}
