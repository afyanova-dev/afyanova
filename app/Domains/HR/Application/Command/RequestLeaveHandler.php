<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\Exception\InsufficientLeaveBalanceException;
use App\Domains\HR\Domain\Exception\InvalidOrganizationStructureException;
use App\Domains\HR\Domain\Exception\OverlappingLeaveRequestException;
use App\Domains\HR\Domain\LeaveLedgerEntryRepository;
use App\Domains\HR\Domain\LeaveRequestRepository;
use App\Domains\HR\Domain\LeaveTypeRepository;
use App\Domains\HR\Domain\Model\LeaveRequest;
use App\Domains\HR\Domain\StaffRepository;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles RequestLeave command (25-hr-and-workforce-domain.md §6, §7.9, §7.12).
 */
final class RequestLeaveHandler
{
    public function __construct(
        private readonly LeaveRequestRepository $leaveRequests,
        private readonly LeaveTypeRepository $leaveTypes,
        private readonly LeaveLedgerEntryRepository $leaveLedger,
        private readonly StaffRepository $staff,
        private readonly OrganizationApi $org,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RequestLeave $command): void
    {
        // 1. Validate staff exists
        $this->staff->getById($command->staffId);

        // 2. Validate facility exists
        if (! $this->org->facilityExists($command->facilityId)) {
            throw InvalidOrganizationStructureException::facilityNotFound($command->facilityId);
        }

        // 3. Validate leave type exists
        $leaveType = $this->leaveTypes->getById($command->leaveTypeId);

        // 4. Invariant 7.12: No overlapping pending or approved leave for the same staff
        if ($this->leaveRequests->hasOverlapping($command->staffId, $command->startDate, $command->endDate)) {
            throw OverlappingLeaveRequestException::forPeriod(
                $command->staffId,
                $command->startDate->toDateString(),
                $command->endDate->toDateString()
            );
        }

        // 5. Invariant 7.9: Balance check if leave type forbids negative balance
        if (! $leaveType->allow_negative) {
            $currentBalance = $this->leaveLedger->getBalance($command->staffId, $command->leaveTypeId);
            $pendingDays = $this->leaveRequests->pendingDaysForStaffAndType($command->staffId, $command->leaveTypeId);

            if (($currentBalance - $pendingDays) < $command->days) {
                throw InsufficientLeaveBalanceException::forStaffAndType(
                    $command->staffId,
                    $command->leaveTypeId,
                    $command->days,
                    $currentBalance - $pendingDays
                );
            }
        }

        // 6. Create request
        $request = LeaveRequest::request(
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            staffId: $command->staffId,
            leaveTypeId: $command->leaveTypeId,
            startDate: $command->startDate,
            endDate: $command->endDate,
            days: $command->days,
            reason: $command->reason,
            leaveRequestId: $command->leaveRequestId,
        );

        $this->leaveRequests->save($request);
    }
}
