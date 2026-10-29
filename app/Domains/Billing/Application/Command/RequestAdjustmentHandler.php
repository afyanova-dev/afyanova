<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\AdjustmentRepository;
use App\Domains\Billing\Domain\Exception\CannotControlClosedAccount;
use App\Domains\Billing\Domain\FinancialAccountRepository;
use App\Domains\Billing\Domain\FinancialControlSettingsReader;
use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\Model\Adjustment;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Domains\Billing\Domain\Model\FinancialControlSettings;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\LedgerEntryType;
use App\Domains\Billing\Domain\ValueObject\LedgerSourceType;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `RequestAdjustment` (ADR-191 G-3 / D7-D) — the Bill.7a
 * `RequestWriteOff` shape with `dualControlThreshold('ADJUSTMENT')` and a
 * signed ledger `ADJUSTMENT` (direction per the type).
 */
final class RequestAdjustmentHandler
{
    public function __construct(
        private readonly AdjustmentRepository $adjustments,
        private readonly FinancialAccountRepository $accounts,
        private readonly FinancialControlSettingsReader $settings,
        private readonly LedgerPoster $ledger,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RequestAdjustment $command): void
    {
        if ($this->adjustments->exists($command->adjustmentId)) {
            return;
        }

        $account = $this->accounts->getById($command->financialAccountId);
        if ((string) $account->status === FinancialAccount::STATUS_CLOSED) {
            throw CannotControlClosedAccount::account($command->financialAccountId);
        }

        $amount = Money::of($command->amount);
        $threshold = $this->settings->dualControlThreshold(FinancialControlSettings::KIND_ADJUSTMENT);
        $autoApprove = $threshold !== null && Money::gte($threshold, $amount);

        $now = CarbonImmutable::now();

        $adjustment = Adjustment::request(
            id: $command->adjustmentId,
            tenantId: $this->context->tenantId(),
            facilityId: (string) $account->facility_id,
            financialAccountId: $command->financialAccountId,
            patientId: (string) $account->patient_id,
            invoiceId: $command->invoiceId,
            amount: $amount,
            currency: 'TZS',
            adjustmentType: $command->adjustmentType,
            direction: $command->direction,
            note: $command->note,
            dualControl: ! $autoApprove,
            requestedByUserId: $command->actorId,
            occurredAt: $now,
            recordedAt: $now,
        );

        if ($autoApprove) {
            $adjustment->apply($command->actorId);
        }

        $this->adjustments->save($adjustment);

        if ($autoApprove) {
            $this->ledger->post(
                financialAccountId: $command->financialAccountId,
                facilityId: (string) $account->facility_id,
                entryType: LedgerEntryType::ADJUSTMENT,
                direction: $command->direction,
                amount: $amount,
                currency: 'TZS',
                sourceType: LedgerSourceType::ADJUSTMENT,
                sourceId: $command->adjustmentId,
            );
        }
    }
}
