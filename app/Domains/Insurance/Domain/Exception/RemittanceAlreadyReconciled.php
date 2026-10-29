<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A remittance cannot be modified because it is already in RECONCILED status (ADR-205).
 * Maps to HTTP 422.
 */
final class RemittanceAlreadyReconciled extends DomainException
{
    public static function forRemittance(string $remittanceId): self
    {
        return new self(sprintf('Remittance [%s] is already fully reconciled.', $remittanceId));
    }

    public function errorCode(): string
    {
        return 'remittance_already_reconciled';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
