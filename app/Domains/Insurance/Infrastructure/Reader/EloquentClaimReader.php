<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Reader;

use App\Domains\Insurance\Contracts\Dto\ClaimLineSummary;
use App\Domains\Insurance\Contracts\Dto\ClaimSummary;
use App\Domains\Insurance\Domain\ClaimReader;
use App\Domains\Insurance\Domain\Model\Claim;
use App\Domains\Insurance\Domain\Model\ClaimLine;

/**
 * Eloquent implementation of {@see ClaimReader} (IC §6.2; ADR-204).
 */
final class EloquentClaimReader implements ClaimReader
{
    public function getById(string $id): ?ClaimSummary
    {
        $claim = Claim::query()->with('lines')->whereKey($id)->first();

        return $claim !== null ? $this->toSummary($claim) : null;
    }

    public function getByClaimNumber(string $claimNumber): ?ClaimSummary
    {
        $claim = Claim::query()->with('lines')->where('claim_number', $claimNumber)->first();

        return $claim !== null ? $this->toSummary($claim) : null;
    }

    public function getPatientClaims(string $patientId, ?string $status = null): array
    {
        $query = Claim::query()
            ->with('lines')
            ->where('patient_id', $patientId)
            ->orderByDesc('created_at');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query->get()->map(fn (Claim $c) => $this->toSummary($c))->all());
    }

    public function getPayerClaims(string $payerId, ?string $status = null): array
    {
        $query = Claim::query()
            ->with('lines')
            ->where('payer_id', $payerId)
            ->orderByDesc('created_at');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query->get()->map(fn (Claim $c) => $this->toSummary($c))->all());
    }

    private function toSummary(Claim $claim): ClaimSummary
    {
        /** @var list<ClaimLineSummary> $lines */
        $lines = $claim->lines->map(fn (ClaimLine $l): ClaimLineSummary => new ClaimLineSummary(
            lineId: (string) $l->id,
            claimId: (string) $l->claim_id,
            chargeId: (string) $l->charge_id,
            serviceCategory: (string) $l->service_category,
            serviceCode: (string) $l->service_code,
            serviceDescription: (string) $l->service_description,
            serviceDate: (string) $l->service_date,
            quantity: (string) $l->quantity,
            unitPrice: (string) $l->unit_price,
            claimedAmount: (string) $l->claimed_amount,
            authorizationId: $l->authorization_id !== null ? (string) $l->authorization_id : null,
            diagnosisCode: $l->diagnosis_code !== null ? (string) $l->diagnosis_code : null,
            lineNumber: (int) $l->line_number,
            lineActive: (bool) $l->line_active,
        ))->all();

        return new ClaimSummary(
            claimId: (string) $claim->id,
            tenantId: (string) $claim->tenant_id,
            facilityId: (string) $claim->facility_id,
            claimNumber: (string) $claim->claim_number,
            invoiceId: (string) $claim->invoice_id,
            patientId: (string) $claim->patient_id,
            payerId: (string) $claim->payer_id,
            planId: (string) $claim->plan_id,
            memberCoverageId: (string) $claim->member_coverage_id,
            contractId: $claim->contract_id !== null ? (string) $claim->contract_id : null,
            authorizationId: $claim->authorization_id !== null ? (string) $claim->authorization_id : null,
            status: (string) $claim->status,
            totalClaimedAmount: (string) $claim->total_claimed_amount,
            currency: (string) $claim->currency,
            readinessStatus: $claim->readiness_status !== null ? (string) $claim->readiness_status : null,
            readinessCheckedAt: $claim->readiness_checked_at?->toIso8601String(),
            readinessNotes: $claim->readiness_notes,
            primaryDiagnosisCode: $claim->primary_diagnosis_code !== null ? (string) $claim->primary_diagnosis_code : null,
            externalReference: $claim->external_reference !== null ? (string) $claim->external_reference : null,
            notes: $claim->notes !== null ? (string) $claim->notes : null,
            supersedesClaimId: $claim->supersedes_claim_id !== null ? (string) $claim->supersedes_claim_id : null,
            supersededByClaimId: $claim->superseded_by_claim_id !== null ? (string) $claim->superseded_by_claim_id : null,
            supersededAt: $claim->superseded_at?->toIso8601String(),
            submittedAt: $claim->submitted_at?->toIso8601String(),
            submittedByUserId: $claim->submitted_by_user_id !== null ? (string) $claim->submitted_by_user_id : null,
            cancelledAt: $claim->cancelled_at?->toIso8601String(),
            cancelledByUserId: $claim->cancelled_by_user_id !== null ? (string) $claim->cancelled_by_user_id : null,
            cancellationReason: $claim->cancellation_reason !== null ? (string) $claim->cancellation_reason : null,
            createdByUserId: (string) $claim->created_by_user_id,
            lines: $lines,
            occurredAt: $claim->occurred_at->toIso8601String(),
            recordedAt: $claim->recorded_at->toIso8601String(),
        );
    }
}
