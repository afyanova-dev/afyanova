<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\ClaimReadinessSummary;
use App\Domains\Insurance\Domain\ClaimLineRepository;
use App\Domains\Insurance\Domain\ClaimRepository;
use App\Domains\Insurance\Domain\Service\ClaimReadinessValidator;

/**
 * Orchestrates `GetClaimReadiness` (ADR-204).
 */
final class GetClaimReadinessHandler
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly ClaimLineRepository $lines,
        private readonly ClaimReadinessValidator $validator,
    ) {}

    public function __invoke(GetClaimReadiness $query): ClaimReadinessSummary
    {
        $claim = $this->claims->getById($query->claimId);
        $claimLines = $this->lines->getLinesForClaim($query->claimId);

        $result = $this->validator->validate($claim, $claimLines);

        return new ClaimReadinessSummary(
            claimId: $claim->id,
            readinessStatus: $result['status'],
            isReady: $result['isReady'],
            blockingReasons: $result['blockingReasons'],
            missingInformation: $result['notes']['missing_information'] ?? [],
            checkedAt: $result['notes']['checked_at'],
        );
    }
}
