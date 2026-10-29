<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Exception\CannotControlClosedAccount;
use App\Domains\Billing\Domain\FinancialAccountRepository;
use App\Domains\Billing\Domain\FinancialControlSettingsReader;
use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Domains\Billing\Domain\Model\FinancialControlSettings;
use App\Domains\Billing\Domain\Model\WriteOff;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\LedgerEntryType;
use App\Domains\Billing\Domain\ValueObject\LedgerSourceType;
use App\Domains\Billing\Domain\WriteOffRepository;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `RequestWriteOff` (ADR-191 G-3 / D7-A).
 *
 * Idempotent pre-check → load the account (RLS; reject CLOSED) → resolve the
 * per-tenant dual-control threshold: `amount <= threshold` (threshold set) →
 * create the write-off APPLIED (`approved_by = requested_by`) + post the ledger
 * `WRITE_OFF` / `CREDIT`; else → create it `REQUESTED` for a distinct approver.
 */
final class RequestWriteOffHandler
{
    public function __construct(
        private readonly WriteOffRepository $writeOffs,
        private readonly FinancialAccountRepository $accounts,
        private readonly FinancialControlSettingsReader $settings,
        private readonly LedgerPoster $ledger,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RequestWriteOff $command): void
    {
        if ($this->writeOffs->exists($command->writeOffId)) {
            return;
        }

        $account = $this->accounts->getById($command->financialAccountId);
        if ((string) $account->status === FinancialAccount::STATUS_CLOSED) {
            throw CannotControlClosedAccount::account($command->financialAccountId);
        }

        $amount = Money::of($command->amount);
        $threshold = $this->settings->dualControlThreshold(FinancialControlSettings::KIND_WRITE_OFF);
        $autoApprove = $threshold !== null && Money::gte($threshold, $amount);

        $now = CarbonImmutable::now();

        $writeOff = WriteOff::request(
            id: $command->writeOffId,
            tenantId: $this->context->tenantId(),
            facilityId: (string) $account->facility_id,
            financialAccountId: $command->financialAccountId,
            patientId: (string) $account->patient_id,
            invoiceId: $command->invoiceId,
            amount: $amount,
            currency: 'TZS',
            reasonCode: $command->reasonCode,
            note: $command->note,
            dualControl: ! $autoApprove,
            requestedByUserId: $command->actorId,
            occurredAt: $now,
            recordedAt: $now,
        );

        if ($autoApprove) {
            $writeOff->apply($command->actorId);
        }

        $this->writeOffs->save($writeOff);

        if ($autoApprove) {
            $this->ledger->post(
                financialAccountId: $command->financialAccountId,
                facilityId: (string) $account->facility_id,
                entryType: LedgerEntryType::WRITE_OFF,
                direction: 'CREDIT',
                amount: $amount,
                currency: 'TZS',
                sourceType: LedgerSourceType::WRITE_OFF,
                sourceId: $command->writeOffId,
            );
        }
    }
}
