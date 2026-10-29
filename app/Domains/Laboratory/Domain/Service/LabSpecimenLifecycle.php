<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Service;

use App\Domains\Laboratory\Domain\Exception\IllegalLabSpecimenTransition;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;

/**
 * Laboratory-specimen lifecycle rules (14-laboratory-domain.md §35, §156;
 * ADR-158 D-E — the `LabOrderLifecycle` precedent). Pure domain logic, no I/O.
 *
 * Lab.2: COLLECTED -> RECEIVED -> ACCEPTED; COLLECTED | RECEIVED -> REJECTED.
 * ADR-159 (Lab.3a) adds ACCEPTED -> PROCESSING (first result entered).
 * COMPLETED is reserved; EXPECTED / LOST / CANCELLED are declared-not-wired.
 */
final class LabSpecimenLifecycle
{
    /** ADR-159 — first result entered against an ACCEPTED specimen. */
    public function assertCanStartProcessing(string $status): void
    {
        if ($status !== LabSpecimen::STATUS_ACCEPTED) {
            throw IllegalLabSpecimenTransition::fromStatus('startProcessing', $status);
        }
    }

    public function assertCanReceive(string $status): void
    {
        if ($status !== LabSpecimen::STATUS_COLLECTED) {
            throw IllegalLabSpecimenTransition::fromStatus('ReceiveSpecimen', $status);
        }
    }

    public function assertCanAccept(string $status): void
    {
        if ($status !== LabSpecimen::STATUS_RECEIVED) {
            throw IllegalLabSpecimenTransition::fromStatus('AcceptSpecimen', $status);
        }
    }

    public function assertCanReject(string $status): void
    {
        if (! in_array($status, [LabSpecimen::STATUS_COLLECTED, LabSpecimen::STATUS_RECEIVED], true)) {
            throw IllegalLabSpecimenTransition::fromStatus('RejectSpecimen', $status);
        }
    }

    public function assertCanRequestRecollection(string $status): void
    {
        if ($status !== LabSpecimen::STATUS_REJECTED) {
            throw IllegalLabSpecimenTransition::fromStatus('RequestRecollection', $status);
        }
    }
}
