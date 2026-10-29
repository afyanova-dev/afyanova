<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Service;

use App\Domains\CareCoordination\Domain\Exception\IllegalReferralFeedbackTransition;
use App\Domains\CareCoordination\Domain\Exception\IllegalReferralTransition;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;

/**
 * Referral and ReferralFeedback lifecycle rules (26-care-coordination-domain.md
 * §8; ADR-151 D-F — one service handling both entities' transitions, the
 * BedLifecycle multi-entity precedent). Pure domain logic, no I/O.
 */
final class ReferralLifecycle
{
    // --- Referral -----------------------------------------------------

    public function assertCanSend(string $status): void
    {
        if ($status !== Referral::STATUS_DRAFT) {
            throw IllegalReferralTransition::fromStatus('SendReferral', $status);
        }
    }

    public function assertCanReceive(string $status): void
    {
        if ($status !== Referral::STATUS_SENT) {
            throw IllegalReferralTransition::fromStatus('ReceiveReferral', $status);
        }
    }

    public function assertCanAccept(string $status): void
    {
        if ($status !== Referral::STATUS_RECEIVED) {
            throw IllegalReferralTransition::fromStatus('AcceptReferral', $status);
        }
    }

    public function assertCanReject(string $status): void
    {
        if (! in_array($status, [Referral::STATUS_SENT, Referral::STATUS_RECEIVED], true)) {
            throw IllegalReferralTransition::fromStatus('RejectReferral', $status);
        }
    }

    public function assertCanComplete(string $status): void
    {
        if ($status !== Referral::STATUS_ACCEPTED) {
            throw IllegalReferralTransition::fromStatus('CompleteReferral', $status);
        }
    }

    public function assertCanCancel(string $status): void
    {
        if (! in_array($status, [Referral::STATUS_DRAFT, Referral::STATUS_SENT, Referral::STATUS_RECEIVED], true)) {
            throw IllegalReferralTransition::fromStatus('CancelReferral', $status);
        }
    }

    public function assertCanExpire(string $status): void
    {
        if (! in_array($status, [Referral::STATUS_SENT, Referral::STATUS_RECEIVED], true)) {
            throw IllegalReferralTransition::fromStatus('ExpireReferral', $status);
        }
    }

    /**
     * LinkReferralEncounter is only meaningful once the referral has been
     * ACCEPTED (Invariant 7.6 — an accepted internal referral results in
     * exactly one new encounter, then linked back).
     */
    public function assertCanLinkEncounter(string $status): void
    {
        if ($status !== Referral::STATUS_ACCEPTED) {
            throw IllegalReferralTransition::fromStatus('LinkReferralEncounter', $status);
        }
    }

    public function assertCanAcknowledgeHandover(string $status, bool $impliesHandover): void
    {
        if (! $impliesHandover) {
            throw IllegalReferralTransition::fromStatus('AcknowledgeCareHandover (implies_handover is false)', $status);
        }

        if ($status !== Referral::STATUS_ACCEPTED) {
            throw IllegalReferralTransition::fromStatus('AcknowledgeCareHandover', $status);
        }
    }

    // --- ReferralFeedback -----------------------------------------------

    public function assertCanRecordContent(string $status): void
    {
        if (! in_array($status, [ReferralFeedback::STATUS_EXPECTED, ReferralFeedback::STATUS_PARTIAL], true)) {
            throw IllegalReferralFeedbackTransition::fromStatus('RecordReferralFeedback', $status);
        }
    }

    public function assertCanCompleteFeedback(string $status): void
    {
        if ($status !== ReferralFeedback::STATUS_PARTIAL) {
            throw IllegalReferralFeedbackTransition::fromStatus('CompleteReferralFeedback', $status);
        }
    }

    public function assertCanMarkFeedbackNotProvided(string $status): void
    {
        if (! in_array($status, [ReferralFeedback::STATUS_EXPECTED, ReferralFeedback::STATUS_PARTIAL], true)) {
            throw IllegalReferralFeedbackTransition::fromStatus('MarkFeedbackNotProvided', $status);
        }
    }
}
