<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\EmploymentRepository;

/**
 * Handles EndEmployment command (ADR-206).
 */
final class EndEmploymentHandler
{
    public function __construct(
        private readonly EmploymentRepository $employments,
    ) {}

    public function __invoke(EndEmployment $command): void
    {
        $employment = $this->employments->getByIdForUpdate($command->employmentId);

        $employment->end($command->endDate, $command->reason);

        $this->employments->save($employment);
    }
}
