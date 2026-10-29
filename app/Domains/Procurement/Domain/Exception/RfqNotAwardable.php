<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AwardRfq` was dispatched against an RFQ that is not CLOSED
 * (19-procurement-domain.md §31, §37; ADR-173 D-D). Maps to HTTP 409.
 */
final class RfqNotAwardable extends DomainException
{
    public static function status(string $rfqId, string $status): self
    {
        return new self(sprintf('RFQ [%s] is [%s] — it must be CLOSED before it can be awarded.', $rfqId, $status));
    }

    public function errorCode(): string
    {
        return 'rfq_not_awardable';
    }
}
