<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\ClaimLineRepository;
use App\Domains\Insurance\Domain\ClaimRepository;
use App\Domains\Insurance\Domain\Exception\ClaimNotReadyForSubmission;
use App\Domains\Insurance\Domain\Exception\IllegalClaimTransition;
use App\Domains\Insurance\Domain\Service\ClaimReadinessValidator;
use App\Domains\Insurance\Domain\ValueObject\ClaimReadinessStatus;
use App\Domains\Insurance\Domain\ValueObject\ClaimStatus;

/**
 * Orchestrates `SubmitClaim` (ADR-204; Invariant 8).
 */
final class SubmitClaimHandler
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly ClaimLineRepository $lines,
        private readonly ClaimReadinessValidator $validator,
    ) {}

    public function __invoke(SubmitClaim $command): void
    {
        $claim = $this->claims->getByIdForUpdate($command->claimId);

        if (! in_array($claim->status, [ClaimStatus::DRAFT, ClaimStatus::READY], true)) {
            throw IllegalClaimTransition::fromStatus('SubmitClaim', (string) $claim->status);
        }

        $claimLines = $this->lines->getLinesForClaim($command->claimId);

        // Always re-run deterministic readiness check to prevent stale READY submissions
        $result = $this->validator->validate($claim, $claimLines);
        if (! $result['isReady']) {
            $claim->setReadiness($result['status'], $result['notes']);
            $this->claims->save($claim);

            throw ClaimNotReadyForSubmission::withReasons($command->claimId, $result['blockingReasons']);
        }

        $claim->setReadiness(ClaimReadinessStatus::READY, $result['notes']);
        $claim->submit($command->actorId, $command->externalReference);

        $this->claims->save($claim);
    }
}
