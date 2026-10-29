<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application;

use App\Domains\Billing\Domain\BillableEventRepository;
use App\Domains\Billing\Domain\BillingCatalogReader;
use App\Domains\Billing\Domain\ChargeRepository;
use App\Domains\Billing\Domain\Exception\BillingServiceNotFound;
use App\Domains\Billing\Domain\FinancialAccountRepository;
use App\Domains\Billing\Domain\ItemBillingMapReader;
use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\Model\BillableEvent;
use App\Domains\Billing\Domain\Model\Charge;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\BillablePendingReason;
use App\Domains\Billing\Domain\ValueObject\LedgerEntryType;
use App\Domains\Billing\Domain\ValueObject\LedgerSourceType;
use App\Domains\Billing\Domain\ValueObject\TariffStatus;
use App\Domains\Billing\Infrastructure\Sequence\FinancialAccountNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The shared "turn a captured billable event into a Charge" routine (ADR-189 D-E)
 * — invoked by `CaptureBillableEventHandler` (from `OnMedicationDispensed`) and
 * `RetryBillableEventHandler` (the manual worklist). Runs inside the caller's
 * transaction.
 *
 * The steps: resolve the item → billing service; resolve the Bill.2 tariff
 * (INACTIVE / unpriced → the event stays PENDING with a `pending_reason`);
 * resolve-or-lazy-open the `FinancialAccount` (ADR-189 INV-1); post the Charge +
 * the ledger DEBIT (the first writer of `bill_account_ledger_entries`); mark the
 * billable event CHARGED.
 */
final class ChargeAttempt
{
    public function __construct(
        private readonly BillableEventRepository $billableEvents,
        private readonly ChargeRepository $charges,
        private readonly FinancialAccountRepository $accounts,
        private readonly BillingCatalogReader $catalog,
        private readonly ItemBillingMapReader $itemMap,
        private readonly LedgerPoster $ledger,
        private readonly FinancialAccountNumberSequence $accountNumbers,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  string  $openedByUserId  the acting user for a lazy-opened account
     *                                  (the dispensing pharmacist — INV-1)
     */
    public function attempt(BillableEvent $event, string $openedByUserId): void
    {
        $map = $this->itemMap->activeForItem((string) $event->item_id);
        if ($map === null) {
            $this->hold($event, BillablePendingReason::ITEM_UNMAPPED);

            return;
        }

        try {
            $tariff = $this->catalog->resolveTariff(
                $map->billingServiceId,
                (string) $event->facility_id,
                $event->occurred_at->toDateString(),
            );
        } catch (BillingServiceNotFound) {
            $this->hold($event, BillablePendingReason::ITEM_UNMAPPED);

            return;
        }

        if ($tariff->serviceStatus !== 'ACTIVE') {
            $this->hold($event, BillablePendingReason::SERVICE_INACTIVE);

            return;
        }

        if ($tariff->resolvedScope === TariffStatus::SCOPE_NONE || $tariff->amount === null) {
            $this->hold($event, BillablePendingReason::SERVICE_UNPRICED);

            return;
        }

        $quantity = (string) $event->quantity;
        if (! is_numeric($quantity)) {
            throw new RuntimeException("Billable event [{$event->id}] has a non-numeric quantity (ADR-189 D-E).");
        }

        $unitPrice = Money::of($tariff->amount);
        $amount = Money::multiply($unitPrice, $quantity);

        $now = CarbonImmutable::now();

        $account = $this->resolveAccount(
            (string) $event->patient_id,
            (string) $event->facility_id,
            $openedByUserId,
            $now,
        );

        $charge = Charge::post(
            id: (string) Str::uuid7(),
            tenantId: $this->context->tenantId(),
            facilityId: (string) $event->facility_id,
            financialAccountId: (string) $account->id,
            patientId: (string) $event->patient_id,
            billingServiceId: $map->billingServiceId,
            billingServiceCode: $this->serviceCode($map->billingServiceId),
            billableEventId: (string) $event->id,
            sourceRef: (string) $event->source_ref,
            quantity: $quantity,
            unitPrice: $unitPrice,
            tariffRowId: (string) $tariff->tariffRowId,
            resolvedScope: $tariff->resolvedScope,
            amount: $amount,
            currency: 'TZS',
            occurredAt: $now,
            recordedAt: $now,
        );
        $this->charges->save($charge);

        $this->ledger->post(
            financialAccountId: (string) $account->id,
            facilityId: (string) $event->facility_id,
            entryType: LedgerEntryType::CHARGE,
            direction: 'DEBIT',
            amount: $amount,
            currency: 'TZS',
            sourceType: LedgerSourceType::CHARGE,
            sourceId: (string) $charge->id,
        );

        $event->markCharged((string) $charge->id);
        $this->billableEvents->save($event);
    }

    private function hold(BillableEvent $event, string $reason): void
    {
        $event->holdPending($reason);
        $this->billableEvents->save($event);
    }

    private function serviceCode(string $billingServiceId): string
    {
        return (string) $this->catalog->serviceSummary($billingServiceId)->code;
    }

    private function resolveAccount(
        string $patientId,
        string $facilityId,
        string $openedByUserId,
        CarbonImmutable $now,
    ): FinancialAccount {
        $account = $this->accounts->openAccountForPatientAtFacilityForUpdate($patientId, $facilityId);

        if ($account === null) {
            $fresh = FinancialAccount::open(
                id: (string) Str::uuid7(),
                tenantId: $this->context->tenantId(),
                facilityId: $facilityId,
                patientId: $patientId,
                accountNumber: $this->accountNumbers->next($this->context->tenantId()),
                currency: 'TZS',
                openedByUserId: $openedByUserId,
                occurredAt: $now,
                recordedAt: $now,
            );
            $fresh->activate();

            try {
                $this->accounts->save($fresh);

                return $fresh;
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'bill_financial_accounts_one_active_per_scope')) {
                    throw $e;
                }
                $account = $this->accounts->openAccountForPatientAtFacilityForUpdate($patientId, $facilityId)
                    ?? throw new RuntimeException('Financial account vanished after a unique-violation race (ADR-189 C2).');
            }
        }

        $account->activate();
        $this->accounts->save($account);

        return $account;
    }
}
