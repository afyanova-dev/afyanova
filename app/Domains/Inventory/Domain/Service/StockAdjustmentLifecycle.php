<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Service;

use App\Domains\Inventory\Domain\Exception\IllegalStockAdjustmentTransition;
use App\Domains\Inventory\Domain\Exception\SelfApprovalNotPermitted;
use App\Domains\Inventory\Domain\Model\StockAdjustment;

/**
 * Stock-adjustment lifecycle rules (18-inventory-and-warehousing-domain.md §94,
 * §95, §224, §250, Inv 11, Inv 12; ADR-170 D-H — the `ImagingOrderLifecycle`
 * precedent). Pure domain logic, no I/O.
 *
 *   PENDING_APPROVAL → APPROVED | REJECTED
 */
final class StockAdjustmentLifecycle
{
    public function assertCanApprove(string $status): void
    {
        if ($status !== StockAdjustment::STATUS_PENDING_APPROVAL) {
            throw IllegalStockAdjustmentTransition::fromStatus('ApproveStockAdjustment', $status);
        }
    }

    public function assertCanReject(string $status): void
    {
        if ($status !== StockAdjustment::STATUS_PENDING_APPROVAL) {
            throw IllegalStockAdjustmentTransition::fromStatus('RejectStockAdjustment', $status);
        }
    }

    /** §224 / Inv 12 — the approver must not be the requester (the T.1 interim floor). */
    public function assertNotSelfApproval(string $adjustmentId, string $requestedByUserId, string $actorUserId): void
    {
        if ($requestedByUserId === $actorUserId) {
            throw SelfApprovalNotPermitted::forAdjustment($adjustmentId);
        }
    }
}
