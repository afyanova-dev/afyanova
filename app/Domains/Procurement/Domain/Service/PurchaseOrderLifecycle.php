<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Service;

use App\Domains\Procurement\Domain\Exception\IllegalPurchaseOrderTransition;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;

/**
 * Purchase-order lifecycle rules (19-procurement-domain.md §44, §67, §73, §74;
 * ADR-171 D-G / D-H). Pure domain logic, no I/O.
 *
 *   DRAFT -> SUBMITTED -> APPROVED -> ISSUED -> PARTIALLY_RECEIVED -> FULLY_RECEIVED
 *   ISSUED | PARTIALLY_RECEIVED -> CLOSED_SHORT (P1-10)
 *   DRAFT | SUBMITTED | APPROVED | ISSUED | PARTIALLY_RECEIVED -> CANCELLED
 *   Amendment: ISSUED only (§67 — F3b)
 *   Receipt progress: ISSUED | PARTIALLY_RECEIVED (ADR-172 D-F)
 * ACKNOWLEDGED + CLOSED are Proc.4. The receipt tail (PARTIALLY_RECEIVED /
 * FULLY_RECEIVED) is rolled ONLY by `RecordPurchaseOrderReceiptHandler`.
 *
 * Each rule is exposed twice: `canX()` answers the question (the screen shows
 * only the actions that are legal now) and `assertCanX()` throws (the command
 * guard). One table, two callers — the screen can never offer a move the
 * handler will refuse.
 */
final class PurchaseOrderLifecycle
{
    /** @var list<string> */
    private const CANCELLABLE = [
        PurchaseOrder::STATUS_DRAFT,
        PurchaseOrder::STATUS_SUBMITTED,
        PurchaseOrder::STATUS_APPROVED,
        PurchaseOrder::STATUS_ISSUED,
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED, // §73 — cancel the outstanding balance
    ];

    /** @var list<string> */
    private const RECEIVABLE = [
        PurchaseOrder::STATUS_ISSUED,
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
    ];

    public function canSubmit(string $status): bool
    {
        return $status === PurchaseOrder::STATUS_DRAFT;
    }

    public function canApprove(string $status): bool
    {
        return $status === PurchaseOrder::STATUS_SUBMITTED;
    }

    public function canIssue(string $status): bool
    {
        return $status === PurchaseOrder::STATUS_APPROVED;
    }

    public function canAmend(string $status): bool
    {
        return $status === PurchaseOrder::STATUS_ISSUED;
    }

    public function canRecordReceipt(string $status): bool
    {
        return in_array($status, self::RECEIVABLE, true);
    }

    public function canCancel(string $status): bool
    {
        return in_array($status, self::CANCELLABLE, true);
    }

    /** P1-10 — only an order already with the supplier can be closed short. */
    public function canCloseShort(string $status): bool
    {
        return in_array($status, self::RECEIVABLE, true);
    }

    public function assertCanSubmit(string $status): void
    {
        if (! $this->canSubmit($status)) {
            throw IllegalPurchaseOrderTransition::fromStatus('SubmitPurchaseOrder', $status);
        }
    }

    public function assertCanApprove(string $status): void
    {
        if (! $this->canApprove($status)) {
            throw IllegalPurchaseOrderTransition::fromStatus('ApprovePurchaseOrder', $status);
        }
    }

    public function assertCanIssue(string $status): void
    {
        if (! $this->canIssue($status)) {
            throw IllegalPurchaseOrderTransition::fromStatus('IssuePurchaseOrder', $status);
        }
    }

    public function assertCanAmend(string $status): void
    {
        if (! $this->canAmend($status)) {
            throw IllegalPurchaseOrderTransition::fromStatus('AmendPurchaseOrder', $status);
        }
    }

    /** ADR-172 D-F — receipt progress is legal from ISSUED or PARTIALLY_RECEIVED. */
    public function assertCanRecordReceipt(string $status): void
    {
        if (! $this->canRecordReceipt($status)) {
            throw IllegalPurchaseOrderTransition::fromStatus('RecordPurchaseOrderReceipt', $status);
        }
    }

    public function assertCanCancel(string $status): void
    {
        if (! $this->canCancel($status)) {
            throw IllegalPurchaseOrderTransition::fromStatus('CancelPurchaseOrder', $status);
        }
    }

    public function assertCanCloseShort(string $status): void
    {
        if (! $this->canCloseShort($status)) {
            throw IllegalPurchaseOrderTransition::fromStatus('ClosePurchaseOrderShort', $status);
        }
    }
}
