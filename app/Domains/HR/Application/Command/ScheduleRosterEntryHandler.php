<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\Exception\DuplicateRosterSlotException;
use App\Domains\HR\Domain\Exception\InvalidOrganizationStructureException;
use App\Domains\HR\Domain\Exception\StaffNotEligibleForRosterException;
use App\Domains\HR\Domain\Model\RosterEntry;
use App\Domains\HR\Domain\RosterEntryRepository;
use App\Domains\HR\Domain\ShiftPatternRepository;
use App\Domains\HR\Domain\StaffRepository;
use App\Domains\HR\Domain\ValueObject\StaffStatus;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles ScheduleRosterEntry command (25-hr-and-workforce-domain.md §7; ADR-206).
 */
final class ScheduleRosterEntryHandler
{
    public function __construct(
        private readonly RosterEntryRepository $rosterEntries,
        private readonly StaffRepository $staff,
        private readonly ShiftPatternRepository $shiftPatterns,
        private readonly OrganizationApi $org,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(ScheduleRosterEntry $command): void
    {
        // 1. Staff must exist and be ACTIVE
        $staffMember = $this->staff->getById($command->staffId);
        if ($staffMember->status !== StaffStatus::ACTIVE) {
            throw StaffNotEligibleForRosterException::forStaff($staffMember->id, $staffMember->status);
        }

        // 2. Validate organizational structure (Invariant 7.7)
        if (! $this->org->facilityExists($command->facilityId)) {
            throw InvalidOrganizationStructureException::facilityNotFound($command->facilityId);
        }

        if ($command->departmentId !== null && ! $this->org->departmentExists($command->facilityId, $command->departmentId)) {
            throw InvalidOrganizationStructureException::departmentNotFound($command->facilityId, $command->departmentId);
        }

        if ($command->unitId !== null && ! $this->org->unitExists($command->facilityId, $command->unitId)) {
            throw InvalidOrganizationStructureException::unitNotFound($command->facilityId, $command->unitId);
        }

        // 3. Shift pattern must exist
        $pattern = $this->shiftPatterns->getById($command->shiftPatternId);

        // 4. Invariant: Staff cannot have duplicate active roster slots on the same date and shift pattern
        if ($this->rosterEntries->hasActiveSlot($command->staffId, $command->date, $command->shiftPatternId)) {
            throw DuplicateRosterSlotException::forSlot($command->staffId, $command->date->toDateString(), $command->shiftPatternId);
        }

        // 5. Create draft entry
        $entry = RosterEntry::scheduleDraft(
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            departmentId: $command->departmentId,
            unitId: $command->unitId,
            staffId: $command->staffId,
            shiftPatternId: $command->shiftPatternId,
            date: $command->date,
            startTime: $pattern->start_time,
            endTime: $pattern->end_time,
            notes: $command->notes,
            rosterEntryId: $command->rosterEntryId,
        );

        $this->rosterEntries->save($entry);
    }
}
