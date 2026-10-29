<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Event;

/**
 * An individual claim settlement was reconciled against an incoming remittance advice
 * (21-insurance-and-payer-domain.md §98–§104, §239; ADR-198 D-C; ADR-205).
 *
 * Written to `shared_outbox_events` in the `ReconcileRemittanceLine` transaction;
 * consumed by Billing's `OnRemittanceLineSettled` listener to post actual payment
 * and contractual adjustments through Billing's own primitives.
 *
 * PHI-MINIMIZED (ADR-136): domain identifiers + financial settlement figures + payment reference.
 */
final readonly class RemittanceLineSettled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $remittanceId,
        public string $remittanceLineId,
        public string $claimId,
        public string $invoiceId,
        public string $patientId,
        public string $payerId,
        public string $paidAmount,
        public string $contractualAdjustment,
        public string $patientResponsibility,
        public string $deniedAmount,
        public string $paymentReference,
        public string $settledByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
