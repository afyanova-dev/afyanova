<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Contracts\Dto\ChargeSummary;
use App\Domains\Billing\Contracts\Dto\PendingBillableEventSummary;
use App\Domains\Billing\Domain\ChargeReader;
use App\Domains\Billing\Domain\Exception\ChargeNotFound;
use App\Domains\Billing\Domain\Model\BillableEvent;
use App\Domains\Billing\Domain\Model\Charge;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\BillableEventStatus;

/**
 * Eloquent implementation of {@see ChargeReader} (IC §6.2; ADR-189 D-G).
 */
final class EloquentChargeReader implements ChargeReader
{
    public function summary(string $chargeId): ChargeSummary
    {
        $charge = Charge::query()->whereKey($chargeId)->first()
            ?? throw ChargeNotFound::id($chargeId);

        return $this->toChargeSummary($charge);
    }

    public function forAccount(string $financialAccountId): array
    {
        return array_values(
            Charge::query()
                ->where('financial_account_id', $financialAccountId)
                ->orderByDesc('recorded_at')->orderBy('id')
                ->get()
                ->map(fn (Charge $c): ChargeSummary => $this->toChargeSummary($c))
                ->all()
        );
    }

    public function pendingBillableEvents(?string $facilityId): array
    {
        $query = BillableEvent::query()->where('status', BillableEventStatus::PENDING);

        if ($facilityId !== null) {
            $query->where('facility_id', $facilityId);
        }

        return array_values(
            $query->orderBy('recorded_at')->orderBy('id')->get()
                ->map(fn (BillableEvent $e): PendingBillableEventSummary => new PendingBillableEventSummary(
                    billableEventId: (string) $e->id,
                    facilityId: (string) $e->facility_id,
                    sourceDomain: (string) $e->source_domain,
                    sourceEventType: (string) $e->source_event_type,
                    sourceRef: (string) $e->source_ref,
                    patientId: (string) $e->patient_id,
                    itemId: (string) $e->item_id,
                    quantity: (string) $e->quantity,
                    pendingReason: (string) $e->pending_reason,
                    occurredAt: $e->occurred_at->toIso8601String(),
                    recordedAt: $e->recorded_at->toIso8601String(),
                ))
                ->all()
        );
    }

    private function toChargeSummary(Charge $c): ChargeSummary
    {
        return new ChargeSummary(
            chargeId: (string) $c->id,
            financialAccountId: (string) $c->financial_account_id,
            facilityId: (string) $c->facility_id,
            patientId: (string) $c->patient_id,
            billingServiceId: (string) $c->billing_service_id,
            billingServiceCode: (string) $c->billing_service_code,
            billableEventId: (string) $c->billable_event_id,
            sourceRef: (string) $c->source_ref,
            quantity: (string) $c->quantity,
            unitPrice: Money::of((string) $c->unit_price),
            tariffRowId: (string) $c->tariff_row_id,
            resolvedScope: (string) $c->resolved_scope,
            amount: Money::of((string) $c->amount),
            currency: (string) $c->currency,
            status: (string) $c->status,
            reversalId: $c->reversal_id !== null ? (string) $c->reversal_id : null,
            occurredAt: $c->occurred_at->toIso8601String(),
            recordedAt: $c->recorded_at->toIso8601String(),
        );
    }
}
