<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\ClaimLineRepository;
use App\Domains\Insurance\Domain\ClaimRepository;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates `CancelClaim` (ADR-204; Invariant 7).
 */
final class CancelClaimHandler
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly ClaimLineRepository $lines,
    ) {}

    public function __invoke(CancelClaim $command): void
    {
        DB::transaction(function () use ($command): void {
            $claim = $this->claims->getByIdForUpdate($command->claimId);

            $claim->cancel($command->actorId, $command->reason);
            $this->lines->deactivateLinesForClaim($command->claimId);

            $this->claims->save($claim);
        });
    }
}
