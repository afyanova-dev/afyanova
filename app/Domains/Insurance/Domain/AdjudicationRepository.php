<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Domain\Model\Adjudication;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Adjudication aggregate (IC §9; ADR-205).
 * The FIRST repository parameter of adjudication commands — the bus resolves
 * `AdjudicationPolicy` from it.
 */
interface AdjudicationRepository extends Repository
{
    public function getById(string $id): Adjudication;

    public function getByIdForUpdate(string $id): Adjudication;

    public function getByClaimId(string $claimId): ?Adjudication;

    public function existsForClaim(string $claimId): bool;
}
