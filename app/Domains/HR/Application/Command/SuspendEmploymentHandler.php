<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\EmploymentRepository;

/**
 * Handles SuspendEmployment command (ADR-206).
 */
final class SuspendEmploymentHandler
{
    public function __construct(
        private readonly EmploymentRepository $employments,
    ) {}

    public function __invoke(SuspendEmployment $command): void
    {
        $employment = $this->employments->getByIdForUpdate($command->employmentId);

        $employment->suspend($command->reason);

        $this->employments->save($employment);
    }
}
