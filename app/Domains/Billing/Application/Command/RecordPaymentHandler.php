<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\CashSessionRepository;
use App\Domains\Billing\Domain\Exception\CannotPayClosedAccount;
use App\Domains\Billing\Domain\Exception\NoCashSessionOpen;
use App\Domains\Billing\Domain\FinancialAccountRepository;
use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Domains\Billing\Domain\Model\Payment;
use App\Domains\Billing\Domain\Model\Receipt;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\PaymentRepository;
use App\Domains\Billing\Domain\ReceiptRepository;
use App\Domains\Billing\Domain\ValueObject\LedgerEntryType;
use App\Domains\Billing\Domain\ValueObject\LedgerSourceType;
use App\Domains\Billing\Domain\ValueObject\PaymentMethod;
use App\Domains\Billing\Infrastructure\Sequence\ReceiptNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates `RecordPayment` (ADR-191 B5-B / B5-C; C6-D / G-4 from Bill.6).
 *
 * `getByIdForUpdate` the account → reject CLOSED → activate if not already
 * ACTIVE → resolve the acting cashier's open cash session at the collection
 * facility (`method = CASH` REQUIRES one — `NoCashSessionOpen`; the check is a
 * BUSINESS RULE, not an authorization path — C6-D); attribute the payment to
 * it (all methods) and `activate()` an OPEN session → `Payment::record` → save
 * → ONE `PAYMENT` / `CREDIT` for the FULL amount, `facility_id` = the account's
 * facility (G-7) → `Receipt::issue` (`RCT-%08d`) → save. Idempotent on
 * `paymentId`.
 */
final class RecordPaymentHandler
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly ReceiptRepository $receipts,
        private readonly FinancialAccountRepository $accounts,
        private readonly CashSessionRepository $cashSessions,
        private readonly LedgerPoster $ledger,
        private readonly ReceiptNumberSequence $receiptNumbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordPayment $command): void
    {
        if ($this->payments->exists($command->paymentId)) {
            return;
        }

        $account = $this->accounts->getByIdForUpdate($command->financialAccountId);

        if ((string) $account->status === FinancialAccount::STATUS_CLOSED) {
            throw CannotPayClosedAccount::account($command->financialAccountId);
        }

        if ((string) $account->status !== FinancialAccount::STATUS_ACTIVE) {
            $account->activate();
            $this->accounts->save($account);
        }

        $now = CarbonImmutable::now();
        $accountFacilityId = (string) $account->facility_id;
        $collectionFacilityId = $this->context->facilityId() ?? $accountFacilityId;
        $amount = Money::of($command->amount);

        $session = $this->cashSessions->liveForCashierAtFacilityForUpdate($command->actorId, $collectionFacilityId);
        if ($command->method === PaymentMethod::CASH && $session === null) {
            throw NoCashSessionOpen::forCashier($command->actorId, $collectionFacilityId);
        }

        $payment = Payment::record(
            id: $command->paymentId,
            tenantId: $this->context->tenantId(),
            facilityId: $accountFacilityId,
            financialAccountId: $command->financialAccountId,
            patientId: (string) $account->patient_id,
            method: $command->method,
            amount: $amount,
            currency: 'TZS',
            paymentReference: $command->paymentReference,
            collectedAtFacilityId: $collectionFacilityId,
            collectedByUserId: $command->actorId,
            cashSessionId: $session !== null ? (string) $session->id : null,
            receivedAt: $now,
            occurredAt: $now,
            recordedAt: $now,
        );
        $this->payments->save($payment);

        if ($session !== null && $session->status === $session::STATUS_OPEN) {
            $session->activate();
            $this->cashSessions->save($session);
        }

        $this->ledger->post(
            financialAccountId: $command->financialAccountId,
            facilityId: $accountFacilityId,
            entryType: LedgerEntryType::PAYMENT,
            direction: 'CREDIT',
            amount: $amount,
            currency: 'TZS',
            sourceType: LedgerSourceType::PAYMENT,
            sourceId: $command->paymentId,
        );

        $receipt = Receipt::issue(
            id: (string) Str::uuid7(),
            tenantId: $this->context->tenantId(),
            facilityId: $accountFacilityId,
            financialAccountId: $command->financialAccountId,
            patientId: (string) $account->patient_id,
            paymentId: $command->paymentId,
            receiptNumber: $this->receiptNumbers->next($this->context->tenantId()),
            method: $command->method,
            amount: $amount,
            currency: 'TZS',
            issuedByUserId: $command->actorId,
            occurredAt: $now,
            recordedAt: $now,
        );
        $this->receipts->save($receipt);
    }
}
