<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Mint the `RECEIVED` prescription stub from a `clinical.PrescriptionIssued`
 * event (16-pharmacy-domain.md §19; ADR-181 D-E — the C1 split).
 *
 * SYSTEM command — dispatched only by the `OnPrescriptionIssued` listener, never
 * from an HTTP path. No `AuthorizesAgainst` / `Auditable` / permission key (the
 * `ExpireBedReservation` / `RecordPurchaseOrderReceipt` / IC §7.3 shape).
 * Idempotent: `idempotencyKey` defaults to `prescribingDecisionId` and the
 * handler no-ops on a `rx_prescriptions` row that already exists for it.
 *
 * Carries ONLY what the PHI-minimal event exposes — NO medication name / dose /
 * quantity (those are fetched later by the authenticated `AcceptPrescription`).
 */
final readonly class IngestPrescription
{
    public string $idempotencyKey;

    public function __construct(
        public string $prescriptionId,
        public string $prescribingDecisionId,
        public string $patientId,
        public string $encounterId,
        public string $facilityId,
        public string $authorUserId,
        public ?string $medicationCodeSystem,
        public ?string $route,
        public ?string $urgency,
        public ?string $changeType,
        public string $occurredAt,
        ?string $idempotencyKey = null,
    ) {
        foreach ([
            'prescriptionId' => $prescriptionId,
            'prescribingDecisionId' => $prescribingDecisionId,
            'patientId' => $patientId,
            'encounterId' => $encounterId,
            'facilityId' => $facilityId,
            'authorUserId' => $authorUserId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("IngestPrescription::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($changeType !== null && $changeType !== 'CORRECTION') {
            throw new InvalidArgumentException('IngestPrescription::$changeType must be null or "CORRECTION".');
        }

        try {
            CarbonImmutable::parse($occurredAt);
        } catch (Throwable) {
            throw new InvalidArgumentException('IngestPrescription::$occurredAt must be an ISO-8601 datetime.');
        }

        $this->idempotencyKey = $idempotencyKey ?? $prescribingDecisionId;
    }
}
