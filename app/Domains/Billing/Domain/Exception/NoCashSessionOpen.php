<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * RecordPayment with method = CASH but the acting cashier has no open cash session at the collection facility (ADR-191 C6-D). Maps to HTTP 409.
 */
final class NoCashSessionOpen extends DomainException
{
    public static function forCashier(string $cashierUserId, string $facilityId): self
    {
        return new self(sprintf(
            'A CASH payment requires an open cash session for cashier [%s] at facility [%s].',
            $cashierUserId, $facilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'no_cash_session_open';
    }
}
