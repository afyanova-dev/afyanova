<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * The actor approving a stock adjustment is the same user who raised it
 * (18-inventory-and-warehousing-domain.md §224, Inv 12; ADR-170 D-H — the
 * interim segregation-of-duties floor, the T.1 precedent). Maps to HTTP 409.
 */
final class SelfApprovalNotPermitted extends DomainException
{
    public static function forAdjustment(string $adjustmentId): self
    {
        return new self(sprintf(
            'Stock adjustment [%s] cannot be approved by the user who raised it (§224 / Inv 12; ADR-170 D-H).',
            $adjustmentId,
        ));
    }

    public function errorCode(): string
    {
        return 'self_approval_not_permitted';
    }
}
