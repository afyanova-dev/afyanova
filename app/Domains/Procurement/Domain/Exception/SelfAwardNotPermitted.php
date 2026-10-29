<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * The actor awarding an RFQ is the same user who created it
 * (19-procurement-domain.md §24, §228, Invariant 29; ADR-173 D-D — the
 * segregation-of-duties floor). Maps to HTTP 409.
 */
final class SelfAwardNotPermitted extends DomainException
{
    public static function forRfq(string $rfqId): self
    {
        return new self(sprintf('RFQ [%s] cannot be awarded by the user who created it (§24 / §228 / Inv 29; ADR-173 D-D).', $rfqId));
    }

    public function errorCode(): string
    {
        return 'self_award_not_permitted';
    }
}
