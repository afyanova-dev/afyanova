<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\LeaveRequestRepository;

/**
 * Handles ConfirmLeaveTaken command (25-hr-and-workforce-domain.md §6, §8).
 */
final class ConfirmLeaveTakenHandler
{
    public function __construct(
        private readonly LeaveRequestRepository $leaveRequests,
    ) {}

    public function __invoke(ConfirmLeaveTaken $command): void
    {
        $request = $this->leaveRequests->getByIdForUpdate($command->leaveRequestId);

        $request->confirmTaken();

        $this->leaveRequests->save($request);
    }
}
