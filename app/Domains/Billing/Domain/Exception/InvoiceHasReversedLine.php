<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `IssueInvoice` was called while an active line references a Charge that has
 * since been REVERSED (20-billing-and-payments-domain.md §88 / §135; ADR-190
 * INV-4 / D-C). The clerk must `RemoveInvoiceLine` it first (a DRAFT invoice is
 * mutable). Maps to HTTP 409.
 */
final class InvoiceHasReversedLine extends DomainException
{
    public static function forCharge(string $chargeId): self
    {
        return new self(sprintf(
            'Cannot issue: line for charge [%s] references a REVERSED charge; remove the line first.',
            $chargeId,
        ));
    }

    public function errorCode(): string
    {
        return 'invoice_has_reversed_line';
    }
}
