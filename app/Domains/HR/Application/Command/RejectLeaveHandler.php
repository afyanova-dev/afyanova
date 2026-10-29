<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\LeaveRequestRepository;

/**
 * Handles RejectLeave command (25-hr-and-workforce-domain.md §6, §8).
 */
final class RejectLeaveHandler
{
    public function __construct(
        private readonly LeaveRequestRepository $leaveRequests,
    ) {}

    public function __invoke(RejectLeave $command): void
    {
        $request = $this->leaveRequests->getByIdForUpdate($command->leaveRequestId);

        $request->reject($command->reviewerId, $command->rejectionReason);

        $this->leaveRequests->save($request);
    }
}
