<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ReverseDispensing` cited a Dispensing that has already been reversed
 * (16-pharmacy-domain.md §60, §128; ADR-185 D-B — one reversal per Dispensing).
 * Maps to HTTP 409.
 */
final class DispensingAlreadyReversed extends DomainException
{
    public static function forDispensing(string $dispensingId): self
    {
        return new self(sprintf(
            'Dispensing [%s] has already been reversed.',
            $dispensingId,
        ));
    }

    public function errorCode(): string
    {
        return 'dispensing_already_reversed';
    }
}
