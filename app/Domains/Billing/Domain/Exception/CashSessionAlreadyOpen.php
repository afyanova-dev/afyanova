<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * OpenCashSession while the cashier already has a live session at this facility (ADR-191 C6-A). Maps to HTTP 409.
 */
final class CashSessionAlreadyOpen extends DomainException
{
    public static function forCashier(string $cashierUserId, string $facilityId): self
    {
        return new self(sprintf(
            'Cashier [%s] already has an open cash session at facility [%s].',
            $cashierUserId, $facilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'cash_session_already_open';
    }
}
