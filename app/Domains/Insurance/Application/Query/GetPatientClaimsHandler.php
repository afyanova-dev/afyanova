<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\ClaimSummary;
use App\Domains\Insurance\Domain\ClaimReader;

/**
 * Orchestrates `GetPatientClaims` (ADR-204).
 */
final class GetPatientClaimsHandler
{
    public function __construct(private readonly ClaimReader $reader) {}

    /**
     * @return list<ClaimSummary>
     */
    public function __invoke(GetPatientClaims $query): array
    {
        return $this->reader->getPatientClaims($query->patientId, $query->status);
    }
}
