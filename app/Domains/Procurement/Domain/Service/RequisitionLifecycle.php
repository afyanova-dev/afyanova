<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Service;

use App\Domains\Procurement\Domain\Exception\IllegalRequisitionTransition;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;

/**
 * Purchase-requisition lifecycle rules (19-procurement-domain.md §21; ADR-171
 * D-E — the `ImagingOrderLifecycle` precedent). Pure domain logic, no I/O.
 *
 *   DRAFT -> SUBMITTED -> APPROVED
 *   DRAFT | SUBMITTED -> CANCELLED
 *   SUBMITTED -> REJECTED
 *
 * Each rule is exposed twice — `canX()` for the screen (offer only what is legal
 * now) and `assertCanX()` for the commands — so the two can never drift.
 */
final class RequisitionLifecycle
{
    public function canSubmit(string $status): bool
    {
        return $status === PurchaseRequisition::STATUS_DRAFT;
    }

    public function canEditDraft(string $status): bool
    {
        return $status === PurchaseRequisition::STATUS_DRAFT;
    }

    public function canApprove(string $status): bool
    {
        return $status === PurchaseRequisition::STATUS_SUBMITTED;
    }

    public function canReject(string $status): bool
    {
        return $status === PurchaseRequisition::STATUS_SUBMITTED;
    }

    public function canCancel(string $status): bool
    {
        return in_array($status, [
            PurchaseRequisition::STATUS_DRAFT,
            PurchaseRequisition::STATUS_SUBMITTED,
        ], true);
    }

    public function assertCanSubmit(string $status): void
    {
        if (! $this->canSubmit($status)) {
            throw IllegalRequisitionTransition::fromStatus('SubmitPurchaseRequisition', $status);
        }
    }

    public function assertCanEditDraft(string $status): void
    {
        if (! $this->canEditDraft($status)) {
            throw IllegalRequisitionTransition::fromStatus('UpdateDraftPurchaseRequisition', $status);
        }
    }

    public function assertCanApprove(string $status): void
    {
        if (! $this->canApprove($status)) {
            throw IllegalRequisitionTransition::fromStatus('ApprovePurchaseRequisition', $status);
        }
    }

    public function assertCanReject(string $status): void
    {
        if (! $this->canReject($status)) {
            throw IllegalRequisitionTransition::fromStatus('RejectPurchaseRequisition', $status);
        }
    }

    public function assertCanCancel(string $status): void
    {
        if (! $this->canCancel($status)) {
            throw IllegalRequisitionTransition::fromStatus('CancelPurchaseRequisition', $status);
        }
    }
}
