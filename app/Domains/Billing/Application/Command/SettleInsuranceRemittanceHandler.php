<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\AdjustmentRepository;
use App\Domains\Billing\Domain\Exception\CannotPayClosedAccount;
use App\Domains\Billing\Domain\FinancialAccountRepository;
use App\Domains\Billing\Domain\InvoiceRepository;
use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\Model\Adjustment;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Domains\Billing\Domain\Model\Payment;
use App\Domains\Billing\Domain\Model\PaymentAllocation;
use App\Domains\Billing\Domain\Model\Receipt;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\PaymentAllocationRepository;
use App\Domains\Billing\Domain\PaymentRepository;
use App\Domains\Billing\Domain\ReceiptRepository;
use App\Domains\Billing\Domain\ValueObject\AdjustmentType;
use App\Domains\Billing\Domain\ValueObject\LedgerEntryType;
use App\Domains\Billing\Domain\ValueObject\LedgerSourceType;
use App\Domains\Billing\Domain\ValueObject\PaymentMethod;
use App\Domains\Billing\Infrastructure\Sequence\ReceiptNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates `SettleInsuranceRemittance` (ADR-205).
 *
 * 1. Payer money -> `Payment::record` (method `INSURANCE_REMITTANCE`), credit ledger, issue receipt.
 * 2. `PaymentAllocation::create` -> allocate to the invoice.
 * 3. Contractual adjustment -> `Adjustment::request` with type `CONTRACTUAL_ALLOWANCE`, applied immediately.
 */
final class SettleInsuranceRemittanceHandler
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentAllocationRepository $allocations,
        private readonly InvoiceRepository $invoices,
        private readonly FinancialAccountRepository $accounts,
        private readonly AdjustmentRepository $adjustments,
        private readonly LedgerPoster $ledger,
        private readonly ReceiptRepository $receipts,
        private readonly ReceiptNumberSequence $receiptNumbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(SettleInsuranceRemittance $command): void
    {
        $invoice = $this->invoices->getByIdForUpdate($command->invoiceId);
        $accountId = (string) $invoice->financial_account_id;
        $account = $this->accounts->getByIdForUpdate($accountId);

        if ((string) $account->status === FinancialAccount::STATUS_CLOSED) {
            throw CannotPayClosedAccount::account($accountId);
        }

        if ((string) $account->status !== FinancialAccount::STATUS_ACTIVE) {
            $account->activate();
            $this->accounts->save($account);
        }

        $now = CarbonImmutable::now();
        $accountFacilityId = (string) $account->facility_id;
        $tenantId = $this->context->tenantId();

        // 1. Post and allocate payment if paidAmount > 0
        $paidAmount = Money::of($command->paidAmount);
        if (bccomp($paidAmount, '0', 2) > 0) {
            $paymentId = (string) Str::uuid7();
            $payment = Payment::record(
                id: $paymentId,
                tenantId: $tenantId,
                facilityId: $accountFacilityId,
                financialAccountId: $accountId,
                patientId: (string) $account->patient_id,
                method: PaymentMethod::INSURANCE_REMITTANCE,
                amount: $paidAmount,
                currency: 'TZS',
                paymentReference: $command->paymentReference,
                collectedAtFacilityId: $accountFacilityId,
                collectedByUserId: $command->settledByUserId,
                cashSessionId: null,
                receivedAt: $now,
                occurredAt: $now,
                recordedAt: $now,
            );
            $this->payments->save($payment);

            $this->ledger->post(
                financialAccountId: $accountId,
                facilityId: $accountFacilityId,
                entryType: LedgerEntryType::PAYMENT,
                direction: 'CREDIT',
                amount: $paidAmount,
                currency: 'TZS',
                sourceType: LedgerSourceType::PAYMENT,
                sourceId: $paymentId,
            );

            $receipt = Receipt::issue(
                id: (string) Str::uuid7(),
                tenantId: $tenantId,
                facilityId: $accountFacilityId,
                financialAccountId: $accountId,
                patientId: (string) $account->patient_id,
                paymentId: $paymentId,
                receiptNumber: $this->receiptNumbers->next($tenantId),
                method: PaymentMethod::INSURANCE_REMITTANCE,
                amount: $paidAmount,
                currency: 'TZS',
                issuedByUserId: $command->settledByUserId,
                occurredAt: $now,
                recordedAt: $now,
            );
            $this->receipts->save($receipt);

            $allocation = PaymentAllocation::create(
                id: (string) Str::uuid7(),
                tenantId: $tenantId,
                paymentId: $paymentId,
                invoiceId: $command->invoiceId,
                financialAccountId: $accountId,
                amount: $paidAmount,
                occurredAt: $now,
                recordedAt: $now,
            );
            $this->allocations->save($allocation);
        }

        // 2. Post contractual allowance adjustment if contractualAdjustment > 0
        $adjAmount = Money::of($command->contractualAdjustment);
        if (bccomp($adjAmount, '0', 2) > 0) {
            $adjustmentId = (string) Str::uuid7();
            $adjustment = Adjustment::request(
                id: $adjustmentId,
                tenantId: $tenantId,
                facilityId: $accountFacilityId,
                financialAccountId: $accountId,
                patientId: (string) $account->patient_id,
                invoiceId: $command->invoiceId,
                amount: $adjAmount,
                currency: 'TZS',
                adjustmentType: AdjustmentType::CONTRACTUAL_ALLOWANCE,
                direction: AdjustmentType::direction(AdjustmentType::CONTRACTUAL_ALLOWANCE),
                note: "Contractual allowance per remittance {$command->paymentReference}",
                dualControl: false,
                requestedByUserId: $command->settledByUserId,
                occurredAt: $now,
                recordedAt: $now,
            );
            $adjustment->apply($command->settledByUserId);
            $this->adjustments->save($adjustment);

            $this->ledger->post(
                financialAccountId: $accountId,
                facilityId: $accountFacilityId,
                entryType: LedgerEntryType::ADJUSTMENT,
                direction: AdjustmentType::direction(AdjustmentType::CONTRACTUAL_ALLOWANCE),
                amount: $adjAmount,
                currency: 'TZS',
                sourceType: LedgerSourceType::ADJUSTMENT,
                sourceId: $adjustmentId,
            );
        }
    }
}
