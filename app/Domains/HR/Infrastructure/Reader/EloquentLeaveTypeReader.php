<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\LeaveTypeSummary;
use App\Domains\HR\Domain\Exception\LeaveTypeNotFoundException;
use App\Domains\HR\Domain\LeaveTypeReader;
use App\Domains\HR\Domain\Model\LeaveType;

/**
 * Eloquent implementation of LeaveTypeReader (25-hr-and-workforce-domain.md §6, §10).
 */
final class EloquentLeaveTypeReader implements LeaveTypeReader
{
    public function summary(string $leaveTypeId): LeaveTypeSummary
    {
        /** @var LeaveType|null $type */
        $type = LeaveType::query()->whereKey($leaveTypeId)->first();

        if ($type === null) {
            throw LeaveTypeNotFoundException::forId($leaveTypeId);
        }

        return $this->toSummary($type);
    }

    public function listAll(bool $onlyActive = true): array
    {
        $query = LeaveType::query();

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        return array_values($query
            ->orderBy('code')
            ->get()
            ->map(fn (LeaveType $t): LeaveTypeSummary => $this->toSummary($t))
            ->all());
    }

    private function toSummary(LeaveType $t): LeaveTypeSummary
    {
        return new LeaveTypeSummary(
            leaveTypeId: $t->id,
            tenantId: $t->tenant_id,
            code: $t->code,
            name: $t->name,
            isPaid: (bool) $t->is_paid,
            accrualRatePerYear: (float) $t->accrual_rate_per_year,
            maxBalance: $t->max_balance !== null ? (float) $t->max_balance : null,
            allowNegative: (bool) $t->allow_negative,
            carryOverLimit: $t->carry_over_limit !== null ? (float) $t->carry_over_limit : null,
            isActive: (bool) $t->is_active,
            version: (int) $t->version,
        );
    }
}
