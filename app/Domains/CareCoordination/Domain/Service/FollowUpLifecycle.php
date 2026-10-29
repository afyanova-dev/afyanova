<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Service;

use App\Domains\CareCoordination\Domain\Exception\FollowUpOutcomeIncomplete;
use App\Domains\CareCoordination\Domain\Exception\IllegalFollowUpTransition;
use App\Domains\CareCoordination\Domain\Model\FollowUp;

/**
 * FollowUp lifecycle rules (26-care-coordination-domain.md §8; ADR-154 D-C).
 * Pure domain logic, no I/O. SCHEDULED is a declared state, not reachable in
 * CC.3a (it needs a Scheduling booking).
 */
final class FollowUpLifecycle
{
    public function assertCanRecordOutcome(string $status): void
    {
        if ($status !== FollowUp::STATUS_PENDING) {
            throw IllegalFollowUpTransition::fromStatus('RecordFollowUpOutcome', $status);
        }
    }

    /**
     * Invariant 7.9 — a follow-up marked KEPT must reference a
     * resulting_encounter_id.
     */
    public function assertOutcomeComplete(string $outcome, ?string $resultingEncounterId): void
    {
        if ($outcome === FollowUp::STATUS_KEPT && ($resultingEncounterId === null || $resultingEncounterId === '')) {
            throw FollowUpOutcomeIncomplete::keptNeedsEncounter();
        }
    }
}
