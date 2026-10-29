<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Service;

use App\Domains\Laboratory\Domain\Exception\IllegalLabOrderTransition;
use App\Domains\Laboratory\Domain\Model\LabOrder;

/**
 * Laboratory-order lifecycle rules (14-laboratory-domain.md §24, §155;
 * ADR-157 D-E — the `BedLifecycle` / `ReferralLifecycle` precedent). Pure
 * domain logic, no I/O.
 *
 * Lab.1 covers the front of the §24 machine:
 *   DRAFT -> ORDERED -> ACCEPTED
 *   DRAFT | ORDERED -> CANCELLED
 *   ORDERED -> REJECTED
 * ADR-158 (Lab.2) adds the reserved specimen tail (ADR-157 D-E):
 *   ACCEPTED -> SPECIMEN_PENDING -> PROCESSING
 * ADR-159 (Lab.3a) adds the reserved result tail:
 *   PROCESSING -> RESULTED -> VERIFIED -> RELEASED
 * (an "at least one" forward-only rollup — the first result / verify / release
 * ratchets the order forward; a strict all-results rollup is a refinement).
 */
final class LabOrderLifecycle
{
    public function assertCanSubmit(string $status): void
    {
        if ($status !== LabOrder::STATUS_DRAFT) {
            throw IllegalLabOrderTransition::fromStatus('SubmitLabOrder', $status);
        }
    }

    /** ADR-158 — first specimen collected for an ACCEPTED order. */
    public function assertCanMarkSpecimenPending(string $status): void
    {
        if ($status !== LabOrder::STATUS_ACCEPTED) {
            throw IllegalLabOrderTransition::fromStatus('markSpecimenPending', $status);
        }
    }

    /** ADR-158 — first specimen received/accepted; skips SPECIMEN_PENDING if collect+receive were one step. */
    public function assertCanMarkProcessing(string $status): void
    {
        if (! in_array($status, [LabOrder::STATUS_ACCEPTED, LabOrder::STATUS_SPECIMEN_PENDING], true)) {
            throw IllegalLabOrderTransition::fromStatus('markProcessing', $status);
        }
    }

    /** ADR-159 — first result entered. */
    public function assertCanMarkResulted(string $status): void
    {
        if ($status !== LabOrder::STATUS_PROCESSING) {
            throw IllegalLabOrderTransition::fromStatus('markResulted', $status);
        }
    }

    /** ADR-159 — first result verified. */
    public function assertCanMarkVerified(string $status): void
    {
        if ($status !== LabOrder::STATUS_RESULTED) {
            throw IllegalLabOrderTransition::fromStatus('markVerified', $status);
        }
    }

    /** ADR-159 — first result released. */
    public function assertCanMarkReleased(string $status): void
    {
        if ($status !== LabOrder::STATUS_VERIFIED) {
            throw IllegalLabOrderTransition::fromStatus('markReleased', $status);
        }
    }

    public function assertCanAccept(string $status): void
    {
        if ($status !== LabOrder::STATUS_ORDERED) {
            throw IllegalLabOrderTransition::fromStatus('AcceptLabOrder', $status);
        }
    }

    public function assertCanReject(string $status): void
    {
        if ($status !== LabOrder::STATUS_ORDERED) {
            throw IllegalLabOrderTransition::fromStatus('RejectLabOrder', $status);
        }
    }

    public function assertCanCancel(string $status): void
    {
        if (! in_array($status, [LabOrder::STATUS_DRAFT, LabOrder::STATUS_ORDERED], true)) {
            throw IllegalLabOrderTransition::fromStatus('CancelLabOrder', $status);
        }
    }
}
