<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\LeaveLedgerEntryRepository;
use App\Domains\HR\Domain\LeaveTypeRepository;
use App\Domains\HR\Domain\Model\LeaveLedgerEntry;
use App\Domains\HR\Domain\StaffRepository;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles PostLeaveLedgerEntry command (25-hr-and-workforce-domain.md §6, §7.9).
 */
final class PostLeaveLedgerEntryHandler
{
    public function __construct(
        private readonly LeaveLedgerEntryRepository $leaveLedger,
        private readonly LeaveTypeRepository $leaveTypes,
        private readonly StaffRepository $staff,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(PostLeaveLedgerEntry $command): void
    {
        $this->staff->getById($command->staffId);
        $this->leaveTypes->getById($command->leaveTypeId);

        $entry = LeaveLedgerEntry::post(
            tenantId: $this->context->tenantId(),
            staffId: $command->staffId,
            leaveTypeId: $command->leaveTypeId,
            kind: $command->kind,
            delta: $command->delta,
            effectiveDate: $command->effectiveDate,
            postedBy: $command->postedBy,
            sourceRef: $command->sourceRef,
            reason: $command->reason,
            ledgerEntryId: $command->ledgerEntryId,
        );

        $this->leaveLedger->save($entry);
    }
}
