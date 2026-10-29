<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain;

use App\Domains\CareCoordination\Contracts\Dto\ReferralSummary;

/**
 * Read-side port for Referral queries (ADR-151 D-I).
 */
interface ReferralReader
{
    public function summary(string $referralId): ReferralSummary;

    /**
     * Inbound queue for a facility (SearchReferrals — §10) — referrals
     * targeting this facility (INTERNAL, target_ref = facilityId) not yet
     * in a terminal status.
     *
     * @return list<ReferralSummary>
     */
    public function inboundQueueForFacility(string $facilityId): array;

    /**
     * @return list<ReferralSummary>
     */
    public function forPatient(string $patientId): array;
}
