<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Contracts\Dto\ChargeSummary;
use App\Domains\Billing\Contracts\Dto\InvoiceLineSummary;
use App\Domains\Billing\Contracts\Dto\InvoiceSummary;
use App\Domains\Billing\Domain\AdjustmentReader;
use App\Domains\Billing\Domain\ChargeReader;
use App\Domains\Billing\Domain\Exception\ChargeNotFound;
use App\Domains\Billing\Domain\Exception\InvoiceNotFound;
use App\Domains\Billing\Domain\InvoiceReader;
use App\Domains\Billing\Domain\Model\Invoice;
use App\Domains\Billing\Domain\Model\InvoiceLine;
use App\Domains\Billing\Domain\Model\Charge;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\PaymentAllocationReader;
use App\Domains\Billing\Domain\ValueObject\InvoiceCoverageStatus;
use App\Domains\Billing\Domain\ValueObject\InvoicePayerType;
use App\Domains\Billing\Domain\ValueObject\ChargeStatus;
use App\Domains\Billing\Domain\ValueObject\InvoicePaymentStatus;
use App\Domains\Billing\Domain\ValueObject\InvoiceStatus;
use App\Domains\Billing\Domain\WriteOffReader;

/**
 * Eloquent implementation of {@see InvoiceReader} (IC §6.2; ADR-190 D-D;
 * ADR-191 B5-D / D7-A). `derivedOutstanding` nets the reversed active-line
 * charges, the active payment allocations, and the APPLIED write-offs /
 * adjustments tagged to the invoice; `paymentStatus` is derived from the
 * allocations only (ADR-190 D1 — never stored).
 */
final class EloquentInvoiceReader implements InvoiceReader
{
    public function __construct(
        private readonly ChargeReader $charges,
        private readonly PaymentAllocationReader $allocations,
        private readonly WriteOffReader $writeOffs,
        private readonly AdjustmentReader $adjustments,
    ) {}

    public function summary(string $invoiceId): InvoiceSummary
    {
        $invoice = Invoice::query()->whereKey($invoiceId)->first()
            ?? throw InvoiceNotFound::id($invoiceId);

        return $this->toSummary($invoice);
    }

    public function forAccount(string $financialAccountId): array
    {
        return array_values(
            Invoice::query()
                ->where('financial_account_id', $financialAccountId)
                ->orderByDesc('recorded_at')->orderByDesc('id')
                ->get()
                ->map(fn (Invoice $i): InvoiceSummary => $this->toSummary($i))
                ->all()
        );
    }

    public function uninvoicedCharges(string $financialAccountId): array
    {
        $claimed = InvoiceLine::query()
            ->where('line_active', true)
            ->pluck('charge_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return array_values(array_filter(
            $this->charges->forAccount($financialAccountId),
            static fn (ChargeSummary $c): bool => $c->status === ChargeStatus::POSTED
                && ! in_array($c->chargeId, $claimed, true),
        ));
    }

    public function dispensingClearedForHandover(string $dispensingId): bool
    {
        $charge = Charge::query()
            ->where('source_ref', $dispensingId)
            ->where('status', ChargeStatus::POSTED)
            ->first();

        if ($charge === null) {
            return false;
        }

        $line = InvoiceLine::query()
            ->where('charge_id', $charge->id)
            ->where('line_active', true)
            ->first();

        if ($line === null) {
            return false;
        }

        $invoice = $this->summary((string) $line->invoice_id);
        if ($invoice->status !== InvoiceStatus::ISSUED) {
            return false;
        }

        $invoiceLine = null;
        $activeLines = 0;
        foreach ($invoice->lines as $candidate) {
            if ($candidate->lineActive && ! $candidate->chargeReversed) {
                $activeLines++;
            }
            if ($candidate->lineId === (string) $line->id && $candidate->lineActive && ! $candidate->chargeReversed) {
                $invoiceLine = $candidate;
            }
        }

        // Payment allocations are invoice-level, not charge-level. A dedicated
        // one-charge invoice is required to prove this dispensing was settled.
        if ($invoiceLine === null || $activeLines !== 1) {
            return false;
        }

        if ($invoice->payerType === InvoicePayerType::SELF_PAY) {
            return Money::gte($invoice->paidAmount, $invoice->net);
        }

        return $invoice->payerType === InvoicePayerType::INSURANCE
            && $invoice->payerId !== null
            && $invoice->coverageConfidence === 'CONFIRMED'
            && in_array($invoiceLine->coverageStatus, [
                InvoiceCoverageStatus::COVERED,
                InvoiceCoverageStatus::PARTIALLY_COVERED,
            ], true)
            && Money::gte($invoice->paidAmount, $invoice->patientResponsibility);
    }

    private function toSummary(Invoice $invoice): InvoiceSummary
    {
        $lines = InvoiceLine::query()
            ->where('invoice_id', $invoice->id)
            ->orderBy('recorded_at')->orderBy('id')
            ->get();

        $reversedActiveAmount = '0';
        $lineSummaries = [];
        $insured = $invoice->payer_id !== null;

        foreach ($lines as $line) {
            $reversed = $this->chargeIsReversed((string) $line->charge_id);
            if ((bool) $line->line_active && $reversed) {
                $reversedActiveAmount = Money::add($reversedActiveAmount, (string) $line->amount);
            }

            $lineSummaries[] = new InvoiceLineSummary(
                lineId: (string) $line->id,
                chargeId: (string) $line->charge_id,
                description: (string) $line->description,
                billingServiceCode: (string) $line->billing_service_code,
                quantity: (string) $line->quantity,
                unitPrice: Money::of((string) $line->unit_price),
                amount: Money::of((string) $line->amount),
                lineActive: (bool) $line->line_active,
                chargeReversed: $reversed,
                serviceCategory: $line->service_category !== null ? (string) $line->service_category : null,
                payerResponsibility: $insured && $line->payer_responsibility !== null
                    ? Money::of((string) $line->payer_responsibility) : null,
                patientResponsibility: $insured && $line->patient_responsibility !== null
                    ? Money::of((string) $line->patient_responsibility) : null,
                coverageStatus: $insured && $line->coverage_status !== null ? (string) $line->coverage_status : null,
            );
        }

        $net = Money::of((string) $invoice->net);
        $invoiceId = (string) $invoice->id;
        $paidAmount = $this->allocations->activeAmountForInvoice($invoiceId);
        $writeOffAmount = $this->writeOffs->appliedAmountForInvoice($invoiceId);
        $adjustmentAmount = $this->adjustments->appliedNetForInvoice($invoiceId);

        $effectiveObligation = Money::subtract($net, $reversedActiveAmount);
        if (Money::isNegative($effectiveObligation)) {
            $effectiveObligation = '0.00';
        }

        if ($invoice->status === InvoiceStatus::VOIDED) {
            $derivedOutstanding = '0.00';
        } else {
            $settled = Money::add(Money::add($paidAmount, $writeOffAmount), $adjustmentAmount);
            $derivedOutstanding = Money::subtract($effectiveObligation, $settled);
            if (Money::isNegative($derivedOutstanding)) {
                $derivedOutstanding = '0.00';
            }
        }

        if (Money::isZero($paidAmount)) {
            $paymentStatus = InvoicePaymentStatus::UNPAID;
        } elseif (Money::isZero($derivedOutstanding)) {
            $paymentStatus = InvoicePaymentStatus::PAID;
        } else {
            $paymentStatus = InvoicePaymentStatus::PARTIALLY_PAID;
        }

        // ADR-201 D-D — the Insurance coverage split (all null / '0.00' for SELF_PAY).
        $payerResponsibility = Money::of((string) $invoice->payer_responsibility);
        $patientResponsibility = $insured
            ? Money::of((string) $invoice->patient_responsibility)
            : $net;
        $voided = $invoice->status === InvoiceStatus::VOIDED;

        if ($voided) {
            $derivedPatientOutstanding = '0.00';
            $payerResponsibilityOutstanding = '0.00';
        } else {
            $patientSettled = Money::add(Money::add($paidAmount, $writeOffAmount), $adjustmentAmount);
            $derivedPatientOutstanding = Money::subtract($patientResponsibility, $patientSettled);
            if (Money::isNegative($derivedPatientOutstanding)) {
                $derivedPatientOutstanding = '0.00';
            }
            // The payer side has no settlement mechanism until Ins.8's remittance.
            $payerResponsibilityOutstanding = $payerResponsibility;
        }

        return new InvoiceSummary(
            invoiceId: (string) $invoice->id,
            invoiceNumber: $invoice->invoice_number !== null ? (string) $invoice->invoice_number : null,
            financialAccountId: (string) $invoice->financial_account_id,
            facilityId: (string) $invoice->facility_id,
            patientId: (string) $invoice->patient_id,
            payerType: (string) $invoice->payer_type,
            status: (string) $invoice->status,
            gross: Money::of((string) $invoice->gross),
            discount: Money::of((string) $invoice->discount),
            tax: Money::of((string) $invoice->tax),
            net: $net,
            paidAmount: $paidAmount,
            writeOffAmount: $writeOffAmount,
            adjustmentAmount: $adjustmentAmount,
            paymentStatus: $paymentStatus,
            derivedOutstanding: $derivedOutstanding,
            currency: (string) $invoice->currency,
            lines: $lineSummaries,
            payerId: $invoice->payer_id !== null ? (string) $invoice->payer_id : null,
            memberCoverageId: $invoice->member_coverage_id !== null ? (string) $invoice->member_coverage_id : null,
            coveragePlanId: $invoice->coverage_plan_id !== null ? (string) $invoice->coverage_plan_id : null,
            payerResponsibility: $payerResponsibility,
            patientResponsibility: $patientResponsibility,
            coverageStatus: $invoice->coverage_status !== null ? (string) $invoice->coverage_status : null,
            coverageSource: $invoice->coverage_source !== null ? (string) $invoice->coverage_source : null,
            coverageConfidence: $invoice->coverage_confidence !== null ? (string) $invoice->coverage_confidence : null,
            coverageEvaluatedAt: $invoice->coverage_evaluated_at?->toIso8601String(),
            derivedPatientOutstanding: $derivedPatientOutstanding,
            payerResponsibilityOutstanding: $payerResponsibilityOutstanding,
            issuedAt: $invoice->issued_at?->toIso8601String(),
            issuedByUserId: $invoice->issued_by_user_id !== null ? (string) $invoice->issued_by_user_id : null,
            voidedAt: $invoice->voided_at?->toIso8601String(),
            voidReasonCode: $invoice->void_reason_code,
            occurredAt: $invoice->occurred_at->toIso8601String(),
            recordedAt: $invoice->recorded_at->toIso8601String(),
        );
    }

    private function chargeIsReversed(string $chargeId): bool
    {
        try {
            return $this->charges->summary($chargeId)->status === ChargeStatus::REVERSED;
        } catch (ChargeNotFound) {
            return false;
        }
    }
}
