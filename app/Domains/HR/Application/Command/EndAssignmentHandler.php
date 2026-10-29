<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\AssignmentRepository;

/**
 * Handles EndAssignment command (25-hr-and-workforce-domain.md §3, §8; ADR-206).
 */
final class EndAssignmentHandler
{
    public function __construct(
        private readonly AssignmentRepository $assignments,
    ) {}

    public function __invoke(EndAssignment $command): void
    {
        $assignment = $this->assignments->getByIdForUpdate($command->assignmentId);

        $assignment->end($command->endedDate, $command->reason);

        $this->assignments->save($assignment);
    }
}
