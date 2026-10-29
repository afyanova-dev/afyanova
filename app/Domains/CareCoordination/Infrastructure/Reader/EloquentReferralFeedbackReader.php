<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Infrastructure\Reader;

use App\Domains\CareCoordination\Contracts\Dto\ReferralFeedbackSummary;
use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Domains\CareCoordination\Domain\ReferralFeedbackReader;

final class EloquentReferralFeedbackReader implements ReferralFeedbackReader
{
    /**
     * @return list<ReferralFeedbackSummary>
     */
    public function forReferral(string $referralId): array
    {
        $rows = ReferralFeedback::query()
            ->where('referral_id', $referralId)
            ->orderBy('sequence')
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $summaries[] = $this->toSummary($row);
        }

        return $summaries;
    }

    /**
     * @return list<ReferralFeedbackSummary>
     */
    public function due(string $facilityId): array
    {
        $rows = ReferralFeedback::query()
            ->where('facility_id', $facilityId)
            ->whereIn('status', [ReferralFeedback::STATUS_EXPECTED, ReferralFeedback::STATUS_PARTIAL])
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $summaries[] = $this->toSummary($row);
        }

        return $summaries;
    }

    private function toSummary(ReferralFeedback $feedback): ReferralFeedbackSummary
    {
        return new ReferralFeedbackSummary(
            feedbackId: (string) $feedback->id,
            facilityId: (string) $feedback->facility_id,
            referralId: (string) $feedback->referral_id,
            sequence: (int) $feedback->sequence,
            kind: $feedback->kind,
            receivedAt: $feedback->received_at?->toISOString(),
            receivedVia: $feedback->received_via,
            summary: $feedback->summary,
            outcome: $feedback->outcome,
            status: (string) $feedback->status,
            createdAt: $feedback->created_at?->toISOString(),
            updatedAt: $feedback->updated_at?->toISOString(),
        );
    }
}
