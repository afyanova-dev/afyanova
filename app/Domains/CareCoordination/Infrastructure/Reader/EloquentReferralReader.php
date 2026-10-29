<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Infrastructure\Reader;

use App\Domains\CareCoordination\Contracts\Dto\ReferralSummary;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\ReferralReader;
use App\Shared\Domain\NotFound;

final class EloquentReferralReader implements ReferralReader
{
    public function summary(string $referralId): ReferralSummary
    {
        $referral = Referral::query()->whereKey($referralId)->first()
            ?? throw NotFound::aggregate(Referral::class, $referralId);

        return $this->toSummary($referral);
    }

    /**
     * @return list<ReferralSummary>
     */
    public function inboundQueueForFacility(string $facilityId): array
    {
        $referrals = Referral::query()
            ->where('target_type', Referral::TARGET_TYPE_INTERNAL)
            ->where('target_ref', $facilityId)
            ->whereNotIn('status', [Referral::STATUS_COMPLETED, Referral::STATUS_REJECTED, Referral::STATUS_CANCELLED, Referral::STATUS_EXPIRED])
            ->get();

        $summaries = [];
        foreach ($referrals as $referral) {
            $summaries[] = $this->toSummary($referral);
        }

        return $summaries;
    }

    /**
     * @return list<ReferralSummary>
     */
    public function forPatient(string $patientId): array
    {
        $referrals = Referral::query()->where('patient_id', $patientId)->get();

        $summaries = [];
        foreach ($referrals as $referral) {
            $summaries[] = $this->toSummary($referral);
        }

        return $summaries;
    }

    private function toSummary(Referral $referral): ReferralSummary
    {
        return new ReferralSummary(
            referralId: (string) $referral->id,
            facilityId: (string) $referral->facility_id,
            patientId: (string) $referral->patient_id,
            direction: (string) $referral->direction,
            impliesHandover: (bool) $referral->implies_handover,
            sourceEncounterId: $referral->source_encounter_id,
            targetType: (string) $referral->target_type,
            targetRef: (string) $referral->target_ref,
            specialtyRequested: $referral->specialty_requested,
            priority: (string) $referral->priority,
            clinicalReasonRef: $referral->clinical_reason_ref,
            consentBasisRef: $referral->consent_basis_ref,
            status: (string) $referral->status,
            targetEncounterId: $referral->target_encounter_id,
            createdAt: $referral->created_at?->toISOString(),
            updatedAt: $referral->updated_at?->toISOString(),
        );
    }
}
