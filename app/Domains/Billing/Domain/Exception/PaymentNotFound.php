<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `GetPayment` / `GetReceipt` named a payment absent from the current tenant
 * scope (ADR-191 B5-C). Maps to HTTP 404.
 */
final class PaymentNotFound extends DomainException
{
    public static function id(string $paymentId): self
    {
        return new self(sprintf('Payment [%s] not found.', $paymentId));
    }

    public function errorCode(): string
    {
        return 'not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
