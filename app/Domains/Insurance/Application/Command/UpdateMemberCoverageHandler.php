<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\MemberCoverageRepository;

/**
 * Orchestrates `UpdateMemberCoverage` (ADR-199 D-B). `getByIdForUpdate` →
 * `amend()` → save.
 */
final class UpdateMemberCoverageHandler
{
    public function __construct(private readonly MemberCoverageRepository $coverages) {}

    public function __invoke(UpdateMemberCoverage $command): void
    {
        $coverage = $this->coverages->getByIdForUpdate($command->memberCoverageId);

        $coverage->amend(
            $command->memberNumber,
            $command->policyNumber,
            $command->cardNumber,
            $command->relationship,
            $command->effectiveTo,
        );

        $this->coverages->save($coverage);
    }
}
