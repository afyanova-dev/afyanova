<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An illegal lifecycle transition on a payer tariff row (ADR-203).
 * Maps to HTTP 409.
 */
final class IllegalPayerTariffTransition extends DomainException
{
    public static function cannotTransition(string $tariffId, string $currentStatus, string $targetStatus): self
    {
        return new self(sprintf(
            'Payer tariff [%s] cannot transition from [%s] to [%s].',
            $tariffId,
            $currentStatus,
            $targetStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_payer_tariff_transition';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
