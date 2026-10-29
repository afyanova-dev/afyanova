<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordPayment` named a CLOSED Financial Account (§301 — a closed account is
 * terminal; ADR-191 B5-B). A prepayment for a patient with no account opens
 * one first (`OpenFinancialAccount`). Maps to HTTP 409.
 */
final class CannotPayClosedAccount extends DomainException
{
    public static function account(string $financialAccountId): self
    {
        return new self(sprintf(
            'Financial account [%s] is CLOSED and cannot receive a payment (§301).',
            $financialAccountId,
        ));
    }

    public function errorCode(): string
    {
        return 'cannot_pay_closed_account';
    }
}
