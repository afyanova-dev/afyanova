<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `GetReceipt` named a payment with no receipt in the current tenant scope
 * (every RECORDED payment has exactly one — this is a lookup miss). Maps to
 * HTTP 404.
 */
final class ReceiptNotFound extends DomainException
{
    public static function forPayment(string $paymentId): self
    {
        return new self(sprintf('No receipt for payment [%s].', $paymentId));
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
