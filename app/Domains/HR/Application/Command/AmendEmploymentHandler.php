<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\EmploymentRepository;
use App\Domains\HR\Domain\PositionRepository;

/**
 * Handles AmendEmployment command (ADR-206).
 * Modifies contract terms; does not own lifecycle transitions.
 */
final class AmendEmploymentHandler
{
    public function __construct(
        private readonly EmploymentRepository $employments,
        private readonly PositionRepository $positions,
    ) {}

    public function __invoke(AmendEmployment $command): void
    {
        // Assert position exists
        $this->positions->getById($command->positionId);

        $employment = $this->employments->getByIdForUpdate($command->employmentId);

        $employment->amend(
            positionId: $command->positionId,
            contractType: $command->contractType,
            fte: $command->fte,
            endDate: $command->endDate,
            probationEndDate: $command->probationEndDate,
            noticePeriodDays: $command->noticePeriodDays,
            termsSummary: $command->termsSummary,
        );

        $this->employments->save($employment);
    }
}
