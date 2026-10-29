<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One Invoice (20-billing-and-payments-domain.md §34 / §287 / §296; ADR-190 D-D).
 * Returned by `GetInvoice` / `GetAccountInvoices` via `InvoiceReader`. NOT
 * `#[TypeScript]`.
 *
 * `net` is the frozen ISSUED total (ADR-190 INV-4). `paidAmount` =
 * `Σ(active bill_payment_allocations to this invoice)` (Bill.5c). `writeOffAmount`
 * = `Σ(APPLIED bill_write_offs tagged to this invoice)`; `adjustmentAmount` =
 * `Σ(APPLIED CREDIT adjustments) − Σ(APPLIED DEBIT adjustments)` tagged to it
 * (Bill.7). `paymentStatus` (`UNPAID | PARTIALLY_PAID | PAID` — payments only)
 * and `derivedOutstanding` are DERIVED, never stored (ADR-190 D1 / ADR-191 B5-D
 * / D7-A):
 *   effectiveObligation = max(0, net − Σ(reversed active-line charges))
 *   derivedOutstanding  = max(0, effectiveObligation − paidAmount − writeOffAmount − adjustmentAmount)
 *                         ('0.00' for VOIDED)
 * — write-offs / adjustments are ACCOUNT-level money (D7-D); these invoice
 * fields are the reader annotation of what has settled THIS document.
 *
 * ADR-201 D-D — the Insurance coverage split (`SetInvoiceCoverage`):
 * `payerType` is `SELF_PAY | INSURANCE`. `patientResponsibility` = the stored
 * column, or `net` when `payerId is null` (SELF_PAY). `payerResponsibility` =
 * the stored column ('0.00' for SELF_PAY).
 *   derivedPatientOutstanding      = VOIDED ? '0.00'
 *       : max(0, patientResponsibility − paidAmount − writeOffAmount − adjustmentAmount)
 *   payerResponsibilityOutstanding = VOIDED ? '0.00' : payerResponsibility
 *       (nothing settles the payer side until the Insurance domain's Ins.8
 *       remittance path — it never decreases in Ins.4).
 * `net` / `gross` / `paidAmount` / `paymentStatus` / `derivedOutstanding` are
 * UNCHANGED whole-invoice figures — a payment still pays the whole invoice down.
 */
final readonly class InvoiceSummary
{
    /**
     * @param  list<InvoiceLineSummary>  $lines
     */
    public function __construct(
        public string $invoiceId,
        public ?string $invoiceNumber,
        public string $financialAccountId,
        public string $facilityId,
        public string $patientId,
        public string $payerType,
        public string $status,
        public string $gross,
        public string $discount,
        public string $tax,
        public string $net,
        public string $paidAmount,
        public string $writeOffAmount,
        public string $adjustmentAmount,
        public string $paymentStatus,
        public string $derivedOutstanding,
        public string $currency,
        public array $lines,
        public ?string $payerId,
        public ?string $memberCoverageId,
        public ?string $coveragePlanId,
        public string $payerResponsibility,
        public string $patientResponsibility,
        public ?string $coverageStatus,
        public ?string $coverageSource,
        public ?string $coverageConfidence,
        public ?string $coverageEvaluatedAt,
        public string $derivedPatientOutstanding,
        public string $payerResponsibilityOutstanding,
        public ?string $issuedAt,
        public ?string $issuedByUserId,
        public ?string $voidedAt,
        public ?string $voidReasonCode,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
