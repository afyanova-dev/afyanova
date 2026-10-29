<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\LeaveLedgerEntryRepository;
use App\Domains\HR\Domain\LeaveRequestRepository;
use App\Domains\HR\Domain\Model\LeaveLedgerEntry;
use App\Domains\HR\Domain\ValueObject\LeaveLedgerKind;
use App\Domains\HR\Domain\ValueObject\LeaveRequestStatus;
use Carbon\CarbonImmutable;

/**
 * Handles CancelLeave command (25-hr-and-workforce-domain.md §6, §8).
 */
final class CancelLeaveHandler
{
    public function __construct(
        private readonly LeaveRequestRepository $leaveRequests,
        private readonly LeaveLedgerEntryRepository $leaveLedger,
    ) {}

    public function __invoke(CancelLeave $command): void
    {
        $request = $this->leaveRequests->getByIdForUpdate($command->leaveRequestId);

        // If previously approved, reverse the deducted leave balance via compensating adjustment
        if ($request->status === LeaveRequestStatus::APPROVED) {
            $compensation = LeaveLedgerEntry::post(
                tenantId: $request->tenant_id,
                staffId: $request->staff_id,
                leaveTypeId: $request->leave_type_id,
                kind: LeaveLedgerKind::ADJUSTMENT,
                delta: abs($request->days),
                effectiveDate: CarbonImmutable::now(),
                postedBy: $command->cancelledBy,
                sourceRef: $request->id,
                reason: sprintf('Compensating adjustment for cancelled leave request [%s]: %s', $request->id, $command->cancellationReason),
            );

            $this->leaveLedger->save($compensation);
        }

        $request->cancel($command->cancellationReason);
        $this->leaveRequests->save($request);
    }
}
