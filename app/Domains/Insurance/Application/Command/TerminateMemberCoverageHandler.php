<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\MemberCoverageRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `TerminateMemberCoverage` (ADR-199 D-B). `getByIdForUpdate` →
 * `terminate()` (idempotent) → save.
 */
final class TerminateMemberCoverageHandler
{
    public function __construct(private readonly MemberCoverageRepository $coverages) {}

    public function __invoke(TerminateMemberCoverage $command): void
    {
        $coverage = $this->coverages->getByIdForUpdate($command->memberCoverageId);

        $coverage->terminate($command->actorId, $command->reason, CarbonImmutable::now());

        $this->coverages->save($coverage);
    }
}
