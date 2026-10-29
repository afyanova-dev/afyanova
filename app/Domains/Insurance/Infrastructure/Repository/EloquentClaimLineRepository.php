<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Repository;

use App\Domains\Insurance\Domain\ClaimLineRepository;
use App\Domains\Insurance\Domain\Model\ClaimLine;

/**
 * Eloquent implementation of {@see ClaimLineRepository} (IC §9; ADR-204).
 */
final class EloquentClaimLineRepository implements ClaimLineRepository
{
    public function save(ClaimLine $line): void
    {
        $line->save();
    }

    public function liveLineExistsForCharge(string $chargeId): bool
    {
        return ClaimLine::query()
            ->where('charge_id', $chargeId)
            ->where('line_active', true)
            ->exists();
    }

    public function getLinesForClaim(string $claimId): array
    {
        /** @var list<ClaimLine> */
        return ClaimLine::query()
            ->where('claim_id', $claimId)
            ->orderBy('line_number')
            ->get()
            ->all();
    }

    public function deactivateLinesForClaim(string $claimId): void
    {
        ClaimLine::query()
            ->where('claim_id', $claimId)
            ->where('line_active', true)
            ->update([
                'line_active' => false,
                'updated_at' => now(),
            ]);
    }
}
