<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * Two patient identities were consolidated — the non-surviving Patient is now
 * MERGED into the surviving one (10-patient-domain.md §21-§24, §94, §132;
 * ADR-118, ADR-128). Downstream domains consume this to reconcile their own
 * references in their own phases; PHASE 5 carries no per-domain payload.
 *
 * PHI-free (IC §7.1.1; §94): identifiers only — NO patient number, name, DOB,
 * phone, reason or evidence.
 */
final readonly class PatientMerged
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $survivingPatientId,
        public string $nonSurvivingPatientId,
        public string $mergeId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
