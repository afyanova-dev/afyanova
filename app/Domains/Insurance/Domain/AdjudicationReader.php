<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Contracts\Dto\AdjudicationSummary;

/**
 * Read-side port for claim adjudication projections (IC §9; ADR-205).
 */
interface AdjudicationReader
{
    public function getById(string $id): AdjudicationSummary;

    public function getByClaimId(string $claimId): ?AdjudicationSummary;
}
