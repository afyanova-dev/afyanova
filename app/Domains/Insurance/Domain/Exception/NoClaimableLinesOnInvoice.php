<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An invoice has no active lines with positive payer responsibility (ADR-204).
 * Maps to HTTP 422.
 */
final class NoClaimableLinesOnInvoice extends DomainException
{
    public static function forInvoice(string $invoiceId): self
    {
        return new self(sprintf(
            'Invoice [%s] has no active, un-reversed lines with payer responsibility > 0.',
            $invoiceId,
        ));
    }

    public function errorCode(): string
    {
        return 'no_claimable_lines_on_invoice';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
