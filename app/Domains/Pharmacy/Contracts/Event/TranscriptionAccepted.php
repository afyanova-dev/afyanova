<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Event;

/**
 * A transcribed paper prescription was accepted (Pharm.4) — the paper-path
 * twin of `PrescriptionAccepted`, carrying provenance instead of a clinical
 * decision link. PHI-minimal, no consumer.
 */
final readonly class TranscriptionAccepted
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $prescriptionId,
        public string $prescriberName,
        public string $acceptedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
