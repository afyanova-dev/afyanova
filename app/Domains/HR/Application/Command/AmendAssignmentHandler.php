<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\AssignmentRepository;
use App\Domains\HR\Domain\Exception\InvalidOrganizationStructureException;
use App\Domains\Organization\Contracts\OrganizationApi;

/**
 * Handles AmendAssignment command (25-hr-and-workforce-domain.md §3, §7; ADR-206).
 */
final class AmendAssignmentHandler
{
    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly OrganizationApi $org,
    ) {}

    public function __invoke(AmendAssignment $command): void
    {
        $assignment = $this->assignments->getByIdForUpdate($command->assignmentId);

        if ($command->departmentId !== null && ! $this->org->departmentExists($assignment->facility_id, $command->departmentId)) {
            throw InvalidOrganizationStructureException::departmentNotFound($assignment->facility_id, $command->departmentId);
        }

        if ($command->unitId !== null && ! $this->org->unitExists($assignment->facility_id, $command->unitId)) {
            throw InvalidOrganizationStructureException::unitNotFound($assignment->facility_id, $command->unitId);
        }

        // If promoting to primary, demote existing primary assignment if it's not this one
        if ($command->isPrimary === true) {
            $existingPrimary = $this->assignments->getActivePrimaryAssignment($assignment->staff_id);
            if ($existingPrimary !== null && $existingPrimary->id !== $assignment->id) {
                $existingPrimary->demotePrimary();
                $this->assignments->save($existingPrimary);
            }
        }

        $assignment->amend(
            departmentId: $command->departmentId,
            unitId: $command->unitId,
            scope: $command->scope,
            effectiveFrom: $command->effectiveFrom,
            effectiveTo: $command->effectiveTo,
            isPrimary: $command->isPrimary,
        );

        $this->assignments->save($assignment);
    }
}
