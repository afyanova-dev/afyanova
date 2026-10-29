<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Contracts\Dto\WriteOffSummary;
use App\Domains\Billing\Domain\Exception\WriteOffNotFound;
use App\Domains\Billing\Domain\Model\WriteOff;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\FinancialControlStatus;
use App\Domains\Billing\Domain\WriteOffReader;

final class EloquentWriteOffReader implements WriteOffReader
{
    public function summary(string $writeOffId): WriteOffSummary
    {
        $wo = WriteOff::query()->whereKey($writeOffId)->first()
            ?? throw WriteOffNotFound::id($writeOffId);

        return $this->toSummary($wo);
    }

    public function forAccount(string $financialAccountId): array
    {
        return array_values(
            WriteOff::query()
                ->where('financial_account_id', $financialAccountId)
                ->orderByDesc('recorded_at')->orderByDesc('id')
                ->get()
                ->map(fn (WriteOff $w): WriteOffSummary => $this->toSummary($w))
                ->all()
        );
    }

    public function pending(?string $facilityId): array
    {
        return array_values(
            WriteOff::query()
                ->where('status', FinancialControlStatus::REQUESTED)
                ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
                ->orderBy('recorded_at')->orderBy('id')
                ->get()
                ->map(fn (WriteOff $w): WriteOffSummary => $this->toSummary($w))
                ->all()
        );
    }

    public function appliedAmountForInvoice(string $invoiceId): string
    {
        return Money::of((string) WriteOff::query()
            ->where('invoice_id', $invoiceId)
            ->where('status', WriteOff::STATUS_APPLIED)
            ->sum('amount'));
    }

    private function toSummary(WriteOff $wo): WriteOffSummary
    {
        return new WriteOffSummary(
            writeOffId: (string) $wo->id,
            financialAccountId: (string) $wo->financial_account_id,
            facilityId: (string) $wo->facility_id,
            patientId: (string) $wo->patient_id,
            invoiceId: $wo->invoice_id !== null ? (string) $wo->invoice_id : null,
            amount: Money::of((string) $wo->amount),
            currency: (string) $wo->currency,
            reasonCode: (string) $wo->reason_code,
            status: (string) $wo->status,
            note: $wo->note,
            dualControl: (bool) $wo->dual_control,
            requestedAt: $wo->requested_at->toIso8601String(),
            requestedByUserId: (string) $wo->requested_by_user_id,
            approvedAt: $wo->approved_at?->toIso8601String(),
            approvedByUserId: $wo->approved_by_user_id !== null ? (string) $wo->approved_by_user_id : null,
            rejectedAt: $wo->rejected_at?->toIso8601String(),
            rejectedByUserId: $wo->rejected_by_user_id !== null ? (string) $wo->rejected_by_user_id : null,
            rejectReasonCode: $wo->reject_reason_code,
            reversalId: $wo->reversal_id !== null ? (string) $wo->reversal_id : null,
            reversedAt: $wo->reversed_at?->toIso8601String(),
            reverseReasonCode: $wo->reverse_reason_code,
            recordedAt: $wo->recorded_at->toIso8601String(),
        );
    }
}
