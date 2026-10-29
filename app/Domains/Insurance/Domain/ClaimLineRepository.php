<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Domain\Model\ClaimLine;

/**
 * Write-side port for the ClaimLine entity (IC §9; Invariant 7; ADR-204).
 */
interface ClaimLineRepository
{
    public function save(ClaimLine $line): void;

    public function liveLineExistsForCharge(string $chargeId): bool;

    /**
     * @return list<ClaimLine>
     */
    public function getLinesForClaim(string $claimId): array;

    public function deactivateLinesForClaim(string $claimId): void;
}
