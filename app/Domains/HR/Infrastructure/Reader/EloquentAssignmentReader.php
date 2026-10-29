<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\AssignmentSummary;
use App\Domains\HR\Domain\AssignmentReader;
use App\Domains\HR\Domain\Exception\AssignmentNotFoundException;
use App\Domains\HR\Domain\Model\Assignment;
use App\Domains\HR\Domain\ValueObject\AssignmentStatus;

/**
 * Eloquent implementation of AssignmentReader (25-hr-and-workforce-domain.md §6, §10; ADR-206).
 */
final class EloquentAssignmentReader implements AssignmentReader
{
    public function summary(string $assignmentId): AssignmentSummary
    {
        /** @var Assignment|null $assignment */
        $assignment = Assignment::query()->whereKey($assignmentId)->first();

        if ($assignment === null) {
            throw AssignmentNotFoundException::forId($assignmentId);
        }

        return $this->toSummary($assignment);
    }

    public function openAssignmentsCount(string $staffId): int
    {
        return Assignment::query()
            ->where('staff_id', $staffId)
            ->where('status', AssignmentStatus::ACTIVE->value)
            ->count();
    }

    public function getActiveAssignments(?string $facilityId = null, ?string $departmentId = null, ?string $unitId = null): array
    {
        $query = Assignment::query()->where('status', AssignmentStatus::ACTIVE->value);

        if ($facilityId !== null) {
            $query->where('facility_id', $facilityId);
        }
        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }
        if ($unitId !== null) {
            $query->where('unit_id', $unitId);
        }

        return array_values($query
            ->orderByDesc('is_primary')
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (Assignment $a) => $this->toSummary($a))
            ->all());
    }

    public function forStaff(string $staffId): array
    {
        return array_values(Assignment::query()
            ->where('staff_id', $staffId)
            ->orderByDesc('is_primary')
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (Assignment $a) => $this->toSummary($a))
            ->all());
    }

    private function toSummary(Assignment $a): AssignmentSummary
    {
        return new AssignmentSummary(
            assignmentId: $a->id,
            tenantId: $a->tenant_id,
            staffId: $a->staff_id,
            facilityId: $a->facility_id,
            departmentId: $a->department_id,
            unitId: $a->unit_id,
            scope: $a->scope->value,
            effectiveFrom: $a->effective_from->toDateString(),
            effectiveTo: $a->effective_to?->toDateString(),
            isPrimary: $a->is_primary,
            status: $a->status->value,
            endReason: $a->end_reason,
            version: $a->version,
        );
    }
}
