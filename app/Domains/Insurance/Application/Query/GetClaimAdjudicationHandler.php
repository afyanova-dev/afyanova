<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\AdjudicationSummary;
use App\Domains\Insurance\Domain\AdjudicationReader;

/**
 * Orchestrates `GetClaimAdjudication` (ADR-205).
 */
final class GetClaimAdjudicationHandler
{
    public function __construct(private readonly AdjudicationReader $reader) {}

    public function __invoke(GetClaimAdjudication $query): ?AdjudicationSummary
    {
        return $this->reader->getByClaimId($query->claimId);
    }
}
