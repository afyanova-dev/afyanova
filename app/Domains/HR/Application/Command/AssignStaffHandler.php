<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\AssignmentRepository;
use App\Domains\HR\Domain\Exception\InvalidOrganizationStructureException;
use App\Domains\HR\Domain\Model\Assignment;
use App\Domains\HR\Domain\StaffRepository;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles AssignStaff command (25-hr-and-workforce-domain.md §3, §7; ADR-206).
 *
 * Invariant 7.5: Carries zero RBAC roles or permissions.
 * Invariant 7.7: Validates existence against OrganizationApi.
 */
final class AssignStaffHandler
{
    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly StaffRepository $staff,
        private readonly OrganizationApi $org,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AssignStaff $command): void
    {
        // 1. Assert staff exists
        $this->staff->getById($command->staffId);

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

        // 3. If primary, demote any existing active primary assignment atomically
        if ($command->isPrimary) {
            $existingPrimary = $this->assignments->getActivePrimaryAssignment($command->staffId);
            if ($existingPrimary !== null) {
                $existingPrimary->demotePrimary();
                $this->assignments->save($existingPrimary);
            }
        }

        // 4. Create and persist assignment
        $assignment = Assignment::create(
            tenantId: $this->context->tenantId(),
            staffId: $command->staffId,
            facilityId: $command->facilityId,
            departmentId: $command->departmentId,
            unitId: $command->unitId,
            scope: $command->scope,
            effectiveFrom: $command->effectiveFrom,
            effectiveTo: $command->effectiveTo,
            isPrimary: $command->isPrimary,
            assignmentId: $command->assignmentId,
        );

        $this->assignments->save($assignment);
    }
}
