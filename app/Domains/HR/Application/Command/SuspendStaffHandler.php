<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\StaffRepository;

/**
 * Handles SuspendStaff command (ADR-206).
 */
final class SuspendStaffHandler
{
    public function __construct(
        private readonly StaffRepository $staff,
    ) {}

    public function __invoke(SuspendStaff $command): void
    {
        $staff = $this->staff->getByIdForUpdate($command->staffId);

        $staff->suspend($command->reason);

        $this->staff->save($staff);
    }
}
