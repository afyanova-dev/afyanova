<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Duplicate detection returned BLOCK — a high-confidence identity collision
 * (ADR-117; 10-patient-domain.md §17-20, Invariant 5). RegisterPatient is
 * rejected with HTTP 409. The matched candidate id(s) travel on the exception
 * so the caller can resolve to an existing patient or re-submit with a
 * permissioned override; the fuller privacy-limited candidate view is on the
 * detector result, not echoed into any event.
 */
final class PatientRegistrationBlocked extends DomainException
{
    /**
     * @param  list<string>  $candidatePatientIds
     * @param  list<\App\Domains\Patient\Application\Command\DuplicateCandidateView>  $candidates
     */
    public function __construct(
        public readonly array $candidatePatientIds,
        public readonly string $confidenceBand,
        string $message = 'Registration blocked: a matching patient identity already exists in this tenant.',
        public readonly ?string $collidingField = null,
        public readonly ?string $candidateSummary = null,
        public readonly array $candidates = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'patient_registration_blocked';
    }
}
