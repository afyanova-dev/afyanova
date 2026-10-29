<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Service;

use App\Domains\Radiology\Domain\Exception\IllegalImagingOrderTransition;
use App\Domains\Radiology\Domain\Model\ImagingOrder;

/**
 * Imaging-order lifecycle rules (15-radiology-domain.md §20, §104–§105, §161;
 * ADR-165 D-F — the `LabOrderLifecycle` / `BedLifecycle` precedent). Pure domain
 * logic, no I/O.
 *
 * Rad.1 covers the FRONT of the §20 / §104 machine:
 *   DRAFT -> ORDERED -> ACCEPTED
 *   DRAFT | ORDERED -> CANCELLED
 *   ORDERED -> REJECTED
 * ADR-166 (Rad.2) adds the reserved study-rollup tail (ADR-165 D-F):
 *   ACCEPTED -> SCHEDULED -> IN_PROGRESS -> COMPLETED
 * ADR-167 (Rad.3a) adds the reserved report-rollup tail (§20 + ADR-165 D-B):
 *   COMPLETED -> REPORTED -> VERIFIED -> RELEASED
 * a forward-only "first study" / "first report" rollup — the first study /
 * report to reach each state ratchets the order forward; existing transitions
 * are untouched. Order-state and study-state otherwise stay independent
 * (§105, §161).
 */
final class ImagingOrderLifecycle
{
    /** ADR-167 — first report created for a COMPLETED order. */
    public function assertCanMarkReported(string $status): void
    {
        if ($status !== ImagingOrder::STATUS_COMPLETED) {
            throw IllegalImagingOrderTransition::fromStatus('markReported', $status);
        }
    }

    /** ADR-167 — first report verified. */
    public function assertCanMarkReportVerified(string $status): void
    {
        if (! in_array($status, [ImagingOrder::STATUS_COMPLETED, ImagingOrder::STATUS_REPORTED], true)) {
            throw IllegalImagingOrderTransition::fromStatus('markReportVerified', $status);
        }
    }

    /** ADR-167 — first report released. */
    public function assertCanMarkReleased(string $status): void
    {
        if (! in_array($status, [ImagingOrder::STATUS_REPORTED, ImagingOrder::STATUS_VERIFIED], true)) {
            throw IllegalImagingOrderTransition::fromStatus('markReleased', $status);
        }
    }

    /** ADR-166 — first study scheduled for an ACCEPTED order. */
    public function assertCanMarkScheduled(string $status): void
    {
        if ($status !== ImagingOrder::STATUS_ACCEPTED) {
            throw IllegalImagingOrderTransition::fromStatus('markScheduled', $status);
        }
    }

    /** ADR-166 — first study started; skips SCHEDULED if schedule+start were one step. */
    public function assertCanMarkInProgress(string $status): void
    {
        if (! in_array($status, [ImagingOrder::STATUS_ACCEPTED, ImagingOrder::STATUS_SCHEDULED], true)) {
            throw IllegalImagingOrderTransition::fromStatus('markInProgress', $status);
        }
    }

    /** ADR-166 — first study completed. */
    public function assertCanMarkCompleted(string $status): void
    {
        if (! in_array($status, [ImagingOrder::STATUS_SCHEDULED, ImagingOrder::STATUS_IN_PROGRESS], true)) {
            throw IllegalImagingOrderTransition::fromStatus('markCompleted', $status);
        }
    }

    public function assertCanSubmit(string $status): void
    {
        if ($status !== ImagingOrder::STATUS_DRAFT) {
            throw IllegalImagingOrderTransition::fromStatus('SubmitImagingOrder', $status);
        }
    }

    public function assertCanAccept(string $status): void
    {
        if ($status !== ImagingOrder::STATUS_ORDERED) {
            throw IllegalImagingOrderTransition::fromStatus('AcceptImagingOrder', $status);
        }
    }

    public function assertCanReject(string $status): void
    {
        if ($status !== ImagingOrder::STATUS_ORDERED) {
            throw IllegalImagingOrderTransition::fromStatus('RejectImagingOrder', $status);
        }
    }

    public function assertCanCancel(string $status): void
    {
        if (! in_array($status, [ImagingOrder::STATUS_DRAFT, ImagingOrder::STATUS_ORDERED], true)) {
            throw IllegalImagingOrderTransition::fromStatus('CancelImagingOrder', $status);
        }
    }
}
