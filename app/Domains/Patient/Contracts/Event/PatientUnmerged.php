<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A previously-merged patient identity was restored — the identity-layer merge
 * was undone (10-patient-domain.md §25, §94, §132; ADR-118, ADR-128). Mirrors
 * {@see PatientMerged}. Downstream domains that reconciled on PatientMerged
 * reverse that in their own phases.
 *
 * PHI-free (IC §7.1.1; §94): identifiers only.
 */
final readonly class PatientUnmerged
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
