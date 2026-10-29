<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\StaffRepository;

/**
 * Handles OnboardStaff command (ADR-206).
 */
final class OnboardStaffHandler
{
    public function __construct(
        private readonly StaffRepository $staff,
    ) {}

    public function __invoke(OnboardStaff $command): void
    {
        $staff = $this->staff->getByIdForUpdate($command->staffId);

        $staff->onboard(
            joinedDate: $command->joinedDate,
            cadreId: $command->cadreId,
            positionId: $command->positionId,
        );

        $this->staff->save($staff);
    }
}
