<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Contracts\Dto\AdjustmentSummary;
use App\Domains\Billing\Domain\AdjustmentReader;
use App\Domains\Billing\Domain\Exception\AdjustmentNotFound;
use App\Domains\Billing\Domain\Model\Adjustment;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\FinancialControlStatus;

final class EloquentAdjustmentReader implements AdjustmentReader
{
    public function summary(string $adjustmentId): AdjustmentSummary
    {
        $adj = Adjustment::query()->whereKey($adjustmentId)->first()
            ?? throw AdjustmentNotFound::id($adjustmentId);

        return $this->toSummary($adj);
    }

    public function forAccount(string $financialAccountId): array
    {
        return array_values(
            Adjustment::query()
                ->where('financial_account_id', $financialAccountId)
                ->orderByDesc('recorded_at')->orderByDesc('id')
                ->get()
                ->map(fn (Adjustment $a): AdjustmentSummary => $this->toSummary($a))
                ->all()
        );
    }

    public function pending(?string $facilityId): array
    {
        return array_values(
            Adjustment::query()
                ->where('status', FinancialControlStatus::REQUESTED)
                ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
                ->orderBy('recorded_at')->orderBy('id')
                ->get()
                ->map(fn (Adjustment $a): AdjustmentSummary => $this->toSummary($a))
                ->all()
        );
    }

    public function appliedNetForInvoice(string $invoiceId): string
    {
        $credit = Money::of((string) Adjustment::query()
            ->where('invoice_id', $invoiceId)->where('status', Adjustment::STATUS_APPLIED)
            ->where('direction', 'CREDIT')->sum('amount'));
        $debit = Money::of((string) Adjustment::query()
            ->where('invoice_id', $invoiceId)->where('status', Adjustment::STATUS_APPLIED)
            ->where('direction', 'DEBIT')->sum('amount'));

        return Money::subtract($credit, $debit);
    }

    private function toSummary(Adjustment $adj): AdjustmentSummary
    {
        return new AdjustmentSummary(
            adjustmentId: (string) $adj->id,
            financialAccountId: (string) $adj->financial_account_id,
            facilityId: (string) $adj->facility_id,
            patientId: (string) $adj->patient_id,
            invoiceId: $adj->invoice_id !== null ? (string) $adj->invoice_id : null,
            amount: Money::of((string) $adj->amount),
            currency: (string) $adj->currency,
            adjustmentType: (string) $adj->adjustment_type,
            direction: (string) $adj->direction,
            status: (string) $adj->status,
            note: $adj->note,
            dualControl: (bool) $adj->dual_control,
            requestedAt: $adj->requested_at->toIso8601String(),
            requestedByUserId: (string) $adj->requested_by_user_id,
            approvedAt: $adj->approved_at?->toIso8601String(),
            approvedByUserId: $adj->approved_by_user_id !== null ? (string) $adj->approved_by_user_id : null,
            rejectedAt: $adj->rejected_at?->toIso8601String(),
            rejectedByUserId: $adj->rejected_by_user_id !== null ? (string) $adj->rejected_by_user_id : null,
            rejectReasonCode: $adj->reject_reason_code,
            reversalId: $adj->reversal_id !== null ? (string) $adj->reversal_id : null,
            reversedAt: $adj->reversed_at?->toIso8601String(),
            reverseReasonCode: $adj->reverse_reason_code,
            recordedAt: $adj->recorded_at->toIso8601String(),
        );
    }
}
