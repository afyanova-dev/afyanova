<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `SetInvoiceCoverage` named a `member_coverage_id` that is not one of the
 * invoice patient's ACTIVE coverages (ADR-201 D-C; 21-insurance-and-payer
 * Invariant 2 — coverage belongs to the right patient). Maps to HTTP 409.
 */
final class CoverageNotForInvoicePatient extends DomainException
{
    public static function mismatch(string $memberCoverageId, string $invoiceId): self
    {
        return new self(sprintf(
            'Member coverage [%s] is not an active coverage of invoice [%s]\'s patient.',
            $memberCoverageId,
            $invoiceId,
        ));
    }

    public function errorCode(): string
    {
        return 'coverage_not_for_invoice_patient';
    }
}
