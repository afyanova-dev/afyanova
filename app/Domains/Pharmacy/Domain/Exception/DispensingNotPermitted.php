<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * The prescribing decision behind this Pharmacy Order is no longer a valid basis
 * for dispensing (16-pharmacy-domain.md §111 Invariant 2; ADR-184 D-D). Checked
 * on the FIRST dispensing of an order. Maps to HTTP 409.
 */
final class DispensingNotPermitted extends DomainException
{
    public static function decisionDiscontinued(string $prescribingDecisionId): self
    {
        return new self(sprintf(
            'The prescribing decision [%s] is DISCONTINUED — dispensing is not permitted.',
            $prescribingDecisionId,
        ));
    }

    public static function decisionSuperseded(string $prescribingDecisionId, string $supersededBy): self
    {
        return new self(sprintf(
            'The prescribing decision [%s] is SUPERSEDED by [%s] — dispense against the corrected prescription.',
            $prescribingDecisionId,
            $supersededBy,
        ));
    }

    public function errorCode(): string
    {
        return 'dispensing_not_permitted';
    }
}
