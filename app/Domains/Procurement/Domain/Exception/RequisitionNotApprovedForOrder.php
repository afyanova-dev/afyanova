<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A purchase order cited a `requisition_id` whose requisition is not APPROVED
 * (19-procurement-domain.md §2, §21; ADR-171 D-G — F11). Maps to HTTP 409.
 */
final class RequisitionNotApprovedForOrder extends DomainException
{
    public static function status(string $requisitionId, string $status): self
    {
        return new self(sprintf(
            'Purchase requisition [%s] is [%s] — a purchase order may only cite an APPROVED requisition.',
            $requisitionId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'requisition_not_approved_for_order';
    }
}
