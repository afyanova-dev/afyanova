<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\LeaveBalanceSummary;
use App\Domains\HR\Contracts\Dto\LeaveLedgerEntrySummary;
use App\Domains\HR\Domain\Exception\LeaveTypeNotFoundException;
use App\Domains\HR\Domain\LeaveLedgerReader;
use App\Domains\HR\Domain\Model\LeaveLedgerEntry;
use App\Domains\HR\Domain\Model\LeaveRequest;
use App\Domains\HR\Domain\Model\LeaveType;
use App\Domains\HR\Domain\ValueObject\LeaveLedgerKind;
use App\Domains\HR\Domain\ValueObject\LeaveRequestStatus;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of LeaveLedgerReader (25-hr-and-workforce-domain.md §6, §7.9, §10).
 */
final class EloquentLeaveLedgerReader implements LeaveLedgerReader
{
    /**
     * @return list<LeaveLedgerEntrySummary>
     */
    public function forStaff(
        string $staffId,
        ?string $leaveTypeId = null,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): array {
        $query = LeaveLedgerEntry::query()
            ->with(['staff.person', 'leaveType'])
            ->where('staff_id', $staffId);

        if ($leaveTypeId !== null) {
            $query->where('leave_type_id', $leaveTypeId);
        }

        if ($from !== null) {
            $query->where('effective_date', '>=', $from->toDateString());
        }

        if ($to !== null) {
            $query->where('effective_date', '<=', $to->toDateString());
        }

        return array_values($query
            ->orderByDesc('effective_date')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LeaveLedgerEntry $e): LeaveLedgerEntrySummary => $this->toSummary($e))
            ->all());
    }

    public function getBalance(string $staffId, string $leaveTypeId): LeaveBalanceSummary
    {
        /** @var LeaveType|null $type */
        $type = LeaveType::query()->whereKey($leaveTypeId)->first();

        if ($type === null) {
            throw LeaveTypeNotFoundException::forId($leaveTypeId);
        }

        return $this->computeBalance($staffId, $type);
    }

    /**
     * @return list<LeaveBalanceSummary>
     */
    public function getAllBalancesForStaff(string $staffId): array
    {
        $types = LeaveType::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        return array_values($types
            ->map(fn (LeaveType $type): LeaveBalanceSummary => $this->computeBalance($staffId, $type))
            ->all());
    }

    private function computeBalance(string $staffId, LeaveType $type): LeaveBalanceSummary
    {
        $entries = LeaveLedgerEntry::query()
            ->where('staff_id', $staffId)
            ->where('leave_type_id', $type->id)
            ->get();

        $accrued = 0.0;
        $taken = 0.0;
        $adjusted = 0.0;
        $balance = 0.0;

        foreach ($entries as $entry) {
            $delta = (float) $entry->delta;
            $balance += $delta;

            if (in_array($entry->kind, [LeaveLedgerKind::OPENING, LeaveLedgerKind::ACCRUAL, LeaveLedgerKind::CARRY_OVER], true)) {
                $accrued += $delta;
            } elseif ($entry->kind === LeaveLedgerKind::TAKEN) {
                $taken += abs($delta);
            } elseif ($entry->kind === LeaveLedgerKind::ADJUSTMENT) {
                $adjusted += $delta;
            }
        }

        $pendingRequestedDays = (float) LeaveRequest::query()
            ->where('staff_id', $staffId)
            ->where('leave_type_id', $type->id)
            ->where('status', LeaveRequestStatus::REQUESTED->value)
            ->sum('days');

        return new LeaveBalanceSummary(
            staffId: $staffId,
            leaveTypeId: $type->id,
            leaveTypeCode: $type->code,
            leaveTypeName: $type->name,
            isPaid: (bool) $type->is_paid,
            allowNegative: (bool) $type->allow_negative,
            accrued: $accrued,
            taken: $taken,
            adjusted: $adjusted,
            balance: $balance,
            pendingRequestedDays: $pendingRequestedDays,
        );
    }

    private function toSummary(LeaveLedgerEntry $e): LeaveLedgerEntrySummary
    {
        $staff = $e->staff;
        $person = $staff?->person;
        $staffName = $person !== null
            ? trim("{$person->first_name} ".($person->middle_name ? "{$person->middle_name} " : '').$person->last_name)
            : null;

        return new LeaveLedgerEntrySummary(
            ledgerEntryId: $e->id,
            tenantId: $e->tenant_id,
            staffId: $e->staff_id,
            staffNumber: $staff?->staff_number,
            staffName: $staffName,
            leaveTypeId: $e->leave_type_id,
            leaveTypeCode: $e->leaveType !== null ? $e->leaveType->code : null,
            leaveTypeName: $e->leaveType !== null ? $e->leaveType->name : null,
            kind: $e->kind->value,
            delta: (float) $e->delta,
            sourceRef: $e->source_ref,
            effectiveDate: $e->effective_date->toDateString(),
            reason: $e->reason,
            postedBy: $e->posted_by,
            createdAt: (string) $e->created_at,
            version: (int) $e->version,
        );
    }
}
