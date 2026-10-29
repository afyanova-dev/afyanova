<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\StaffRepository;

/**
 * Handles ReinstateStaff command (ADR-206).
 */
final class ReinstateStaffHandler
{
    public function __construct(
        private readonly StaffRepository $staff,
    ) {}

    public function __invoke(ReinstateStaff $command): void
    {
        $staff = $this->staff->getByIdForUpdate($command->staffId);

        $staff->reinstate();

        $this->staff->save($staff);
    }
}
