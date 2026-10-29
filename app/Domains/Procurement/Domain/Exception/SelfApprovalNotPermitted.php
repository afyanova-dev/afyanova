<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * The approver is the same user who raised the requisition / created the PO
 * (19-procurement-domain.md §24, §228, Invariant 29; ADR-171 D-F / D-H — the
 * T.1 interim segregation-of-duties floor). Maps to HTTP 409.
 */
final class SelfApprovalNotPermitted extends DomainException
{
    public static function forRequisition(string $requisitionId): self
    {
        return new self(sprintf('Purchase requisition [%s] cannot be approved by the user who raised it (§24 / Inv 29).', $requisitionId));
    }

    public static function forPurchaseOrder(string $poId): self
    {
        return new self(sprintf('Purchase order [%s] cannot be approved by the user who created it (§228 / Inv 29).', $poId));
    }

    public function errorCode(): string
    {
        return 'self_approval_not_permitted';
    }
}
