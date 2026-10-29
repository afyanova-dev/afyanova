<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Enum\DuplicateOutcome;

/**
 * The REVIEW result of RegisterPatient (ADR-117): no patient was created. The
 * caller reuses one of the candidates or re-submits RegisterPatient with
 * `overrideDuplicate: true` and the `patient.patients.override_duplicate`
 * permission.
 */
final readonly class RegisterPatientReview
{
    /**
     * @param  list<DuplicateCandidateView>  $candidates
     */
    public function __construct(
        public array $candidates,
        public string $confidenceBand,
        public DuplicateOutcome $outcome = DuplicateOutcome::Review,
    ) {}
}
