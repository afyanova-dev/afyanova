<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ReverseDispensing` cited a Dispensing that is not in a reversible state
 * (16-pharmacy-domain.md §60, §128; ADR-185 D-D) — it is not COMPLETED. Maps to
 * HTTP 409.
 */
final class DispensingNotReversible extends DomainException
{
    public static function status(string $dispensingId, string $status): self
    {
        return new self(sprintf(
            'Dispensing [%s] is [%s]; only a COMPLETED dispensing can be reversed.',
            $dispensingId,
            $status,
        ));
    }

    /**
     * ADR-186 D-E — the medication has been collected (its Pickup is PICKED_UP);
     * a reversal cannot return stock that has left the pharmacy. The correct
     * remedy is a medication return (§61–§64 — deferred).
     */
    public static function pickedUp(string $dispensingId): self
    {
        return new self(sprintf(
            'Dispensing [%s] has been collected (pickup PICKED_UP); a reversal cannot return stock that has left the pharmacy — use a medication return.',
            $dispensingId,
        ));
    }

    public function errorCode(): string
    {
        return 'dispensing_not_reversible';
    }
}
