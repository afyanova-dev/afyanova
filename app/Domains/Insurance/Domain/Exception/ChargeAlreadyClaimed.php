<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;
use Throwable;

/**
 * A billing charge is already claimed on another active claim line in this tenant (ADR-204;
 * Invariant 7, domain 21 §272).
 * Maps to HTTP 409.
 */
final class ChargeAlreadyClaimed extends DomainException
{
    public static function forCharge(string $chargeId, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Billing charge [%s] is already claimed on an active claim line (Invariant 7, ADR-204).', $chargeId),
            previous: $previous,
        );
    }

    public function errorCode(): string
    {
        return 'charge_already_claimed';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
