<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain;

use App\Domains\Patient\Domain\Service\DuplicateCandidate;
use App\Domains\Patient\Domain\Service\DuplicateSignals;

/**
 * Read-side port for duplicate detection (ADR-117; IC §6.3). Returns the
 * existing patients in the current tenant worth scoring against an incoming
 * registration — a bounded set (loose match on a strong signal), never the
 * whole MPI. Implementation:
 * Infrastructure/Reader/EloquentDuplicateCandidateReader.
 */
interface DuplicateCandidateReader
{
    /**
     * @return list<DuplicateCandidate>
     */
    public function candidatesFor(DuplicateSignals $signals): array;
}
