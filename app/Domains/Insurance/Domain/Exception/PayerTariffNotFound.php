<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A payer tariff was not found in the current tenant scope (ADR-203).
 * Maps to HTTP 404.
 */
final class PayerTariffNotFound extends DomainException
{
    public static function id(string $tariffId): self
    {
        return new self(sprintf('Payer tariff [%s] not found.', $tariffId));
    }

    public function errorCode(): string
    {
        return 'payer_tariff_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
