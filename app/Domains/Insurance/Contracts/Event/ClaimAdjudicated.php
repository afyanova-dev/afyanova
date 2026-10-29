<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Event;

/**
 * A submitted claim was adjudicated by a third-party payer
 * (21-insurance-and-payer-domain.md §84, §238; ADR-198 D-C; ADR-205).
 *
 * Written to `shared_outbox_events` in the `RecordClaimAdjudication` transaction;
 * idempotent consumers key on `eventId`.
 *
 * PHI-MINIMIZED (ADR-136): domain identifiers + adjudication status + monetary breakdown.
 * NO patient demographics, clinical diagnosis text, or provider names.
 */
final readonly class ClaimAdjudicated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $adjudicationId,
        public string $claimId,
        public string $invoiceId,
        public string $patientId,
        public string $payerId,
        public string $status,
        public string $totalClaimedAmount,
        public string $totalApprovedAmount,
        public string $totalDeniedAmount,
        public string $totalPatientResponsibility,
        public string $totalContractualAdjustment,
        public string $currency,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
