<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\ClaimLineRepository;
use App\Domains\Insurance\Domain\ClaimRepository;
use App\Domains\Insurance\Domain\Service\ClaimReadinessValidator;

/**
 * Orchestrates `ValidateClaimReadiness` (ADR-204).
 */
final class ValidateClaimReadinessHandler
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly ClaimLineRepository $lines,
        private readonly ClaimReadinessValidator $validator,
    ) {}

    public function __invoke(ValidateClaimReadiness $command): void
    {
        $claim = $this->claims->getByIdForUpdate($command->claimId);
        $claimLines = $this->lines->getLinesForClaim($command->claimId);

        $result = $this->validator->validate($claim, $claimLines);

        $claim->setReadiness($result['status'], $result['notes']);

        $this->claims->save($claim);
    }
}
