<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\EmploymentSummary;
use App\Domains\HR\Domain\EmploymentReader;
use App\Domains\HR\Domain\Exception\EmploymentNotFoundException;
use App\Domains\HR\Domain\Model\Employment;
use App\Domains\HR\Domain\ValueObject\EmploymentStatus;

/**
 * Eloquent implementation of EmploymentReader (25-hr-and-workforce-domain.md §6; ADR-206).
 */
final class EloquentEmploymentReader implements EmploymentReader
{
    public function summary(string $employmentId): EmploymentSummary
    {
        /** @var Employment|null $employment */
        $employment = Employment::query()
            ->with(['position'])
            ->whereKey($employmentId)
            ->first();

        if ($employment === null) {
            throw EmploymentNotFoundException::forId($employmentId);
        }

        return $this->toSummary($employment);
    }

    public function activeEmploymentForStaff(string $staffId): ?EmploymentSummary
    {
        /** @var Employment|null $employment */
        $employment = Employment::query()
            ->with(['position'])
            ->where('staff_id', $staffId)
            ->where('status', EmploymentStatus::ACTIVE->value)
            ->first();

        return $employment !== null ? $this->toSummary($employment) : null;
    }

    public function openEmploymentsCount(string $staffId): int
    {
        return Employment::query()
            ->where('staff_id', $staffId)
            ->whereIn('status', [EmploymentStatus::ACTIVE->value, EmploymentStatus::SUSPENDED->value])
            ->count();
    }

    public function forStaff(string $staffId): array
    {
        return array_values(Employment::query()
            ->with(['position'])
            ->where('staff_id', $staffId)
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (Employment $e) => $this->toSummary($e))
            ->all());
    }

    private function toSummary(Employment $e): EmploymentSummary
    {
        return new EmploymentSummary(
            employmentId: $e->id,
            tenantId: $e->tenant_id,
            staffId: $e->staff_id,
            positionId: $e->position_id,
            positionTitle: $e->position?->title,
            contractType: $e->contract_type->value,
            status: $e->status->value,
            fte: (string) $e->fte,
            startDate: $e->start_date->toDateString(),
            endDate: $e->end_date?->toDateString(),
            probationEndDate: $e->probation_end_date?->toDateString(),
            noticePeriodDays: $e->notice_period_days,
            termsSummary: $e->terms_summary,
            suspensionReason: $e->suspension_reason,
            endReason: $e->end_reason,
            version: $e->version,
        );
    }
}
