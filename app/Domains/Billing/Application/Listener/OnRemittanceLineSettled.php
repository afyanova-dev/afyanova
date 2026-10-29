<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Listener;

use App\Domains\Billing\Application\Command\SettleInsuranceRemittance;
use App\Domains\Insurance\Contracts\Event\RemittanceLineSettled;
use App\Shared\Bus\CommandBus;

/**
 * Reacts to Insurance's claim remittance settlement fact and posts actual money
 * and adjustments through Billing's own primitives
 * (20-billing-and-payments-domain.md §101–§106; ADR-198 D-C layer 2; ADR-205).
 *
 * Implements the cross-domain financial reconciliation seam:
 *   1. Payer money -> `Payment::record` with method `INSURANCE_REMITTANCE` (lifting ADR-191 G-6 fence).
 *   2. Payment allocation -> `PaymentAllocation::create` to the settled invoice.
 *   3. Contractual write-down -> `Adjustment::request` with type `CONTRACTUAL_ALLOWANCE`.
 *   4. Residual patient responsibility remains the reader-derived outstanding balance.
 *
 * All actions are dispatched through Billing's command bus, preserving tenant RLS,
 * ledger append-only constraints, and idempotency. Insurance writes zero bill_* rows.
 */
final class OnRemittanceLineSettled
{
    public function __construct(
        private readonly CommandBus $bus,
    ) {}

    public function __invoke(RemittanceLineSettled $event): void
    {
        $this->bus->dispatch(new SettleInsuranceRemittance(
            invoiceId: $event->invoiceId,
            paidAmount: $event->paidAmount,
            contractualAdjustment: $event->contractualAdjustment,
            paymentReference: $event->paymentReference,
            settledByUserId: $event->settledByUserId,
            remittanceLineId: $event->remittanceLineId,
            idempotencyKey: "remit-settle-{$event->remittanceLineId}",
        ));
    }
}
