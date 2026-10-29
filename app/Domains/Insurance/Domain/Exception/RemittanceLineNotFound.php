<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A remittance line was not found in the specified remittance advice (ADR-205).
 * Maps to HTTP 404.
 */
final class RemittanceLineNotFound extends DomainException
{
    public static function id(string $remittanceId, string $lineId): self
    {
        return new self(sprintf('Remittance line [%s] not found in remittance [%s].', $lineId, $remittanceId));
    }

    public function errorCode(): string
    {
        return 'remittance_line_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
