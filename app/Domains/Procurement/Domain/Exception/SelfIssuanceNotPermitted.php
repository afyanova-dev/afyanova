<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * The actor issuing a purchase order is the same user who approved it
 * (19-procurement-domain.md §228 "Different Approver / Different Buyer",
 * Invariant 29; ADR-171 D-H — F2). Maps to HTTP 409.
 */
final class SelfIssuanceNotPermitted extends DomainException
{
    public static function forPurchaseOrder(string $poId): self
    {
        return new self(sprintf('Purchase order [%s] cannot be issued by the user who approved it (§228 / Inv 29; ADR-171 D-H).', $poId));
    }

    public function errorCode(): string
    {
        return 'self_issuance_not_permitted';
    }
}
