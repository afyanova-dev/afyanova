<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\AuthorizationReader;
use App\Domains\Insurance\Domain\ClaimRepository;
use App\Domains\Insurance\Domain\Exception\AuthorizationNotFound;

/**
 * Orchestrates `UpdateClaimDraft` (ADR-204).
 */
final class UpdateClaimDraftHandler
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly AuthorizationReader $authReader,
    ) {}

    public function __invoke(UpdateClaimDraft $command): void
    {
        $claim = $this->claims->getByIdForUpdate($command->claimId);

        if ($command->authorizationId !== null) {
            try {
                $auth = $this->authReader->summary($command->authorizationId);
            } catch (AuthorizationNotFound) {
                throw AuthorizationNotFound::id($command->authorizationId);
            }
            if ($auth->memberCoverageId !== (string) $claim->member_coverage_id) {
                throw AuthorizationNotFound::id($command->authorizationId);
            }
        }

        $claim->updateDraft(
            primaryDiagnosisCode: $command->primaryDiagnosisCode,
            authorizationId: $command->authorizationId,
            notes: $command->notes,
        );

        $this->claims->save($claim);
    }
}
