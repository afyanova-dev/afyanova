<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Reader;

use App\Domains\Insurance\Contracts\Dto\RemittanceLineSummary;
use App\Domains\Insurance\Contracts\Dto\RemittanceSummary;
use App\Domains\Insurance\Domain\Exception\RemittanceNotFound;
use App\Domains\Insurance\Domain\Model\Remittance;
use App\Domains\Insurance\Domain\Model\RemittanceLine;
use App\Domains\Insurance\Domain\RemittanceReader;

/**
 * Eloquent implementation of {@see RemittanceReader} (IC §6.2; ADR-205).
 */
final class EloquentRemittanceReader implements RemittanceReader
{
    public function getById(string $id): RemittanceSummary
    {
        $remittance = Remittance::query()->with('lines')->whereKey($id)->first()
            ?? throw RemittanceNotFound::id($id);

        return $this->toSummary($remittance);
    }

    public function getByRemittanceNumber(string $remittanceNumber): ?RemittanceSummary
    {
        $remittance = Remittance::query()->with('lines')->where('remittance_number', $remittanceNumber)->first();

        return $remittance !== null ? $this->toSummary($remittance) : null;
    }

    public function search(?string $payerId = null, ?string $status = null): array
    {
        $query = Remittance::query()->with('lines')->orderByDesc('created_at');

        if ($payerId !== null) {
            $query->where('payer_id', $payerId);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query->get()->map(fn (Remittance $r): RemittanceSummary => $this->toSummary($r))->all());
    }

    private function toSummary(Remittance $r): RemittanceSummary
    {
        $lines = array_values($r->lines->map(fn (RemittanceLine $l): RemittanceLineSummary => new RemittanceLineSummary(
            lineId: (string) $l->id,
            remittanceId: (string) $l->remittance_id,
            lineNumber: (int) $l->line_number,
            claimId: $l->claim_id !== null ? (string) $l->claim_id : null,
            adjudicationId: $l->adjudication_id !== null ? (string) $l->adjudication_id : null,
            externalClaimReference: $l->external_claim_reference !== null ? (string) $l->external_claim_reference : null,
            claimedAmount: (string) $l->claimed_amount,
            paidAmount: (string) $l->paid_amount,
            contractualAdjustment: (string) $l->contractual_adjustment,
            patientResponsibility: (string) $l->patient_responsibility,
            deniedAmount: (string) $l->denied_amount,
            status: (string) $l->status,
            discrepancyReason: $l->discrepancy_reason !== null ? (string) $l->discrepancy_reason : null,
        ))->all());

        return new RemittanceSummary(
            remittanceId: (string) $r->id,
            tenantId: (string) $r->tenant_id,
            facilityId: (string) $r->facility_id,
            remittanceNumber: (string) $r->remittance_number,
            payerId: (string) $r->payer_id,
            paymentReference: (string) $r->payment_reference,
            paymentMethod: (string) $r->payment_method,
            remittanceDate: (string) $r->remittance_date,
            totalAmount: (string) $r->total_amount,
            reconciledAmount: (string) $r->reconciled_amount,
            unreconciledAmount: (string) $r->unreconciled_amount,
            status: (string) $r->status,
            currency: (string) $r->currency,
            notes: $r->notes !== null ? (string) $r->notes : null,
            createdByUserId: (string) $r->created_by_user_id,
            lines: $lines,
            occurredAt: $r->occurred_at->toIso8601String(),
            recordedAt: $r->recorded_at->toIso8601String(),
        );
    }
}
