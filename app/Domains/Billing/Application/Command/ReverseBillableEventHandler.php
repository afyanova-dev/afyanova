<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\BillableEventRepository;
use App\Domains\Billing\Domain\ChargeRepository;
use App\Domains\Billing\Domain\ChargeReversalRepository;
use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\Model\ChargeReversal;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\BillableEventStatus;
use App\Domains\Billing\Domain\ValueObject\LedgerEntryType;
use App\Domains\Billing\Domain\ValueObject\LedgerSourceType;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates `ReverseBillableEvent` (ADR-189 D-F).
 *
 * Find the `bill_billable_events` row by (source_domain PHARMACY, source_ref =
 * dispensing_id):
 *   not found → log + return (out-of-order delivery — the job retries).
 *   REVERSED | CANCELLED → no-op.
 *   PENDING → CANCELLED (nothing was charged — §112).
 *   CHARGED → the Charge → REVERSED (records `ChargeReversed`) + a
 *             `bill_charge_reversals` row + a `CHARGE` / `CREDIT` ledger entry
 *             (`source_type 'CHARGE_REVERSAL'` — no new `entry_type`).
 * FULL reversal only.
 */
final class ReverseBillableEventHandler
{
    private const SOURCE_DOMAIN = 'PHARMACY';

    public function __construct(
        private readonly BillableEventRepository $billableEvents,
        private readonly ChargeRepository $charges,
        private readonly ChargeReversalRepository $reversals,
        private readonly LedgerPoster $ledger,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(ReverseBillableEvent $command): void
    {
        $event = $this->billableEvents->findBySourceRefForUpdate(self::SOURCE_DOMAIN, $command->dispensingId);

        if ($event === null) {
            Log::warning('billing.billable_event.reverse_no_capture', [
                'reversal_event_id' => $command->reversalEventId,
                'dispensing_id' => $command->dispensingId,
            ]);

            return;
        }

        if (in_array($event->status, [BillableEventStatus::REVERSED, BillableEventStatus::CANCELLED], true)) {
            return;
        }

        if ($event->isPending()) {
            $event->markCancelled();
            $this->billableEvents->save($event);

            return;
        }

        // CHARGED
        $charge = $this->charges->getByIdForUpdate((string) $event->charge_id);
        if (! $charge->isPosted() || $this->reversals->existsForCharge((string) $charge->id)) {
            return;
        }

        $now = CarbonImmutable::now();
        $reversalId = (string) Str::uuid7();
        $amount = Money::of((string) $charge->amount);

        $reversal = ChargeReversal::record(
            id: $reversalId,
            tenantId: $this->context->tenantId(),
            facilityId: (string) $charge->facility_id,
            chargeId: (string) $charge->id,
            financialAccountId: (string) $charge->financial_account_id,
            sourceReversalEventId: $command->reversalEventId,
            dispensingId: $command->dispensingId,
            amount: $amount,
            currency: 'TZS',
            reasonCode: $command->reasonCode,
            occurredAt: $now,
            recordedAt: $now,
        );
        $this->reversals->save($reversal);

        $charge->markReversed($reversalId);
        $this->charges->save($charge);

        $this->ledger->post(
            financialAccountId: (string) $charge->financial_account_id,
            facilityId: (string) $charge->facility_id,
            entryType: LedgerEntryType::CHARGE,
            direction: 'CREDIT',
            amount: $amount,
            currency: 'TZS',
            sourceType: LedgerSourceType::CHARGE_REVERSAL,
            sourceId: $reversalId,
        );

        $event->markReversed();
        $this->billableEvents->save($event);
    }
}
