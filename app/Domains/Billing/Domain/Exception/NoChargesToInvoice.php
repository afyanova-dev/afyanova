<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateInvoice` found no eligible charges (POSTED + not reversed + not already
 * claimed by a live invoice line), or `IssueInvoice` was called on an invoice
 * with zero active lines (20-billing-and-payments-domain.md §35; ADR-190 D-C /
 * D8). Maps to HTTP 409.
 */
final class NoChargesToInvoice extends DomainException
{
    public static function forAccount(string $financialAccountId): self
    {
        return new self(sprintf(
            'No eligible POSTED, un-invoiced charges for financial account [%s].',
            $financialAccountId,
        ));
    }

    public static function emptyInvoice(string $invoiceId): self
    {
        return new self(sprintf('Invoice [%s] has no active lines to issue.', $invoiceId));
    }

    public function errorCode(): string
    {
        return 'no_charges_to_invoice';
    }
}
