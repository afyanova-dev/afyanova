<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain;

use App\Domains\CareCoordination\Contracts\Dto\ReferralFeedbackSummary;

/**
 * Read-side port for ReferralFeedback queries (ADR-151 D-I).
 */
interface ReferralFeedbackReader
{
    /**
     * The feedback thread for a referral (GetReferralFeedback — §10).
     *
     * @return list<ReferralFeedbackSummary>
     */
    public function forReferral(string $referralId): array;

    /**
     * Feedback still awaiting completion (status EXPECTED or PARTIAL) for
     * referrals sourced at a facility (GetReferralFeedbackDue — §10).
     *
     * @return list<ReferralFeedbackSummary>
     */
    public function due(string $facilityId): array;
}
