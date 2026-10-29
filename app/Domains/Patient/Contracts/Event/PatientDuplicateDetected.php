<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A registration produced a REVIEW outcome — a meaningful potential duplicate
 * was found and the registration did NOT proceed (ADR-117; 10-patient-domain.md
 * §19, §132). Emitted ONLY on REVIEW: a BLOCK produces no patient and no event,
 * an ALLOW produces PatientRegistered only.
 *
 * PHI-free (IC §7.1.1): candidate patient ids and a confidence band only — no
 * names, no dates of birth. The privacy-limited candidate display data lives
 * in the synchronous command result, never in this event.
 */
final readonly class PatientDuplicateDetected
{
    /**
     * @param  list<string>  $candidatePatientIds
     */
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $attemptedPatientId,
        public string $attemptedByUserId,
        public array $candidatePatientIds,
        public string $confidenceBand,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
