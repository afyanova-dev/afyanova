<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Reader;

use App\Domains\Insurance\Contracts\Dto\AdjudicationLineSummary;
use App\Domains\Insurance\Contracts\Dto\AdjudicationSummary;
use App\Domains\Insurance\Domain\AdjudicationReader;
use App\Domains\Insurance\Domain\Exception\AdjudicationNotFound;
use App\Domains\Insurance\Domain\Model\Adjudication;
use App\Domains\Insurance\Domain\Model\AdjudicationLine;

/**
 * Eloquent implementation of {@see AdjudicationReader} (IC §6.2; ADR-205).
 */
final class EloquentAdjudicationReader implements AdjudicationReader
{
    public function getById(string $id): AdjudicationSummary
    {
        $adjudication = Adjudication::query()->with('lines')->whereKey($id)->first()
            ?? throw AdjudicationNotFound::id($id);

        return $this->toSummary($adjudication);
    }

    public function getByClaimId(string $claimId): ?AdjudicationSummary
    {
        $adjudication = Adjudication::query()->with('lines')->where('claim_id', $claimId)->first();

        return $adjudication !== null ? $this->toSummary($adjudication) : null;
    }

    private function toSummary(Adjudication $adj): AdjudicationSummary
    {
        $lines = array_values($adj->lines->map(fn (AdjudicationLine $l): AdjudicationLineSummary => new AdjudicationLineSummary(
            lineId: (string) $l->id,
            adjudicationId: (string) $l->adjudication_id,
            claimLineId: (string) $l->claim_line_id,
            lineNumber: (int) $l->line_number,
            status: (string) $l->status,
            claimedAmount: (string) $l->claimed_amount,
            approvedAmount: (string) $l->approved_amount,
            deniedAmount: (string) $l->denied_amount,
            patientResponsibility: (string) $l->patient_responsibility,
            contractualAdjustment: (string) $l->contractual_adjustment,
            denialReasonCode: $l->denial_reason_code !== null ? (string) $l->denial_reason_code : null,
            remarks: $l->remarks !== null ? (string) $l->remarks : null,
        ))->all());

        return new AdjudicationSummary(
            adjudicationId: (string) $adj->id,
            tenantId: (string) $adj->tenant_id,
            facilityId: (string) $adj->facility_id,
            adjudicationNumber: (string) $adj->adjudication_number,
            claimId: (string) $adj->claim_id,
            payerId: (string) $adj->payer_id,
            status: (string) $adj->status,
            totalClaimedAmount: (string) $adj->total_claimed_amount,
            totalApprovedAmount: (string) $adj->total_approved_amount,
            totalDeniedAmount: (string) $adj->total_denied_amount,
            totalPatientResponsibility: (string) $adj->total_patient_responsibility,
            totalContractualAdjustment: (string) $adj->total_contractual_adjustment,
            currency: (string) $adj->currency,
            adjudicationDate: (string) $adj->adjudication_date,
            externalReference: $adj->external_reference !== null ? (string) $adj->external_reference : null,
            notes: $adj->notes !== null ? (string) $adj->notes : null,
            adjudicatedByUserId: $adj->adjudicated_by_user_id !== null ? (string) $adj->adjudicated_by_user_id : null,
            createdByUserId: (string) $adj->created_by_user_id,
            lines: $lines,
            occurredAt: $adj->occurred_at->toIso8601String(),
            recordedAt: $adj->recorded_at->toIso8601String(),
        );
    }
}
