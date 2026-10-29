<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\Exception\InsufficientLeaveBalanceException;
use App\Domains\HR\Domain\Exception\RequesterCannotApproveOwnLeaveException;
use App\Domains\HR\Domain\LeaveLedgerEntryRepository;
use App\Domains\HR\Domain\LeaveRequestRepository;
use App\Domains\HR\Domain\LeaveTypeRepository;
use App\Domains\HR\Domain\Model\LeaveLedgerEntry;
use App\Domains\HR\Domain\StaffUserLinkReader;
use App\Domains\HR\Domain\ValueObject\LeaveLedgerKind;

/**
 * Handles ApproveLeave command (25-hr-and-workforce-domain.md §6, §7.9; AGENTS §19).
 */
final class ApproveLeaveHandler
{
    public function __construct(
        private readonly LeaveRequestRepository $leaveRequests,
        private readonly LeaveTypeRepository $leaveTypes,
        private readonly LeaveLedgerEntryRepository $leaveLedger,
        private readonly StaffUserLinkReader $staffUserLinks,
    ) {}

    public function __invoke(ApproveLeave $command): void
    {
        $request = $this->leaveRequests->getByIdForUpdate($command->leaveRequestId);

        // 1. Separation of duties: Approver cannot be the same person as the requesting staff
        $link = $this->staffUserLinks->findByStaff($request->staff_id);
        if ($link !== null && $link->userId === $command->reviewerId) {
            throw RequesterCannotApproveOwnLeaveException::forUser($command->reviewerId, $request->staff_id);
        }

        // 2. Invariant 7.9: Re-verify balance sufficiency at approval time
        $leaveType = $this->leaveTypes->getById($request->leave_type_id);
        if (! $leaveType->allow_negative) {
            $balance = $this->leaveLedger->getBalance($request->staff_id, $request->leave_type_id);
            if ($balance < $request->days) {
                throw InsufficientLeaveBalanceException::forStaffAndType(
                    $request->staff_id,
                    $request->leave_type_id,
                    $request->days,
                    $balance
                );
            }
        }

        // 3. Transition leave request to APPROVED
        $request->approve($command->reviewerId);
        $this->leaveRequests->save($request);

        // 4. Invariant 7.9: Atomically post TAKEN entry to the append-only ledger
        $ledgerEntry = LeaveLedgerEntry::post(
            tenantId: $request->tenant_id,
            staffId: $request->staff_id,
            leaveTypeId: $request->leave_type_id,
            kind: LeaveLedgerKind::TAKEN,
            delta: -abs($request->days),
            effectiveDate: $request->start_date,
            postedBy: $command->reviewerId,
            sourceRef: $request->id,
            reason: sprintf('Approved leave request [%s] from %s to %s', $request->id, $request->start_date->toDateString(), $request->end_date->toDateString()),
        );

        $this->leaveLedger->save($ledgerEntry);
    }
}
