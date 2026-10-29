<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\OnShiftStaffSummary;
use App\Domains\HR\Contracts\Dto\RosterEntrySummary;
use App\Domains\HR\Domain\Exception\RosterEntryNotFoundException;
use App\Domains\HR\Domain\Model\RosterEntry;
use App\Domains\HR\Domain\RosterEntryReader;
use App\Domains\HR\Domain\ValueObject\RosterEntryStatus;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of RosterEntryReader (25-hr-and-workforce-domain.md §7, §10, §13; ADR-206).
 */
final class EloquentRosterEntryReader implements RosterEntryReader
{
    public function summary(string $rosterEntryId): RosterEntrySummary
    {
        /** @var RosterEntry|null $entry */
        $entry = RosterEntry::query()
            ->whereKey($rosterEntryId)
            ->with(['staff.person', 'shiftPattern'])
            ->first();

        if ($entry === null) {
            throw RosterEntryNotFoundException::forId($rosterEntryId);
        }

        return $this->toSummary($entry);
    }

    public function forFacilityAndPeriod(
        string $facilityId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        ?string $departmentId = null,
        ?string $unitId = null,
    ): array {
        $query = RosterEntry::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with(['staff.person', 'shiftPattern']);

        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        if ($unitId !== null) {
            $query->where('unit_id', $unitId);
        }

        return array_values($query
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (RosterEntry $entry) => $this->toSummary($entry))
            ->all());
    }

    public function forStaff(string $staffId, CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        return array_values(RosterEntry::query()
            ->where('staff_id', $staffId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with(['staff.person', 'shiftPattern'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (RosterEntry $entry) => $this->toSummary($entry))
            ->all());
    }

    public function getOnShiftNow(
        string $facilityId,
        ?string $unitId = null,
        ?CarbonImmutable $atTime = null,
    ): array {
        $time = $atTime ?? CarbonImmutable::now();
        $currentDate = $time->toDateString();
        $yesterdayDate = $time->subDay()->toDateString();
        $currentTime = $time->format('H:i:s');

        $query = RosterEntry::query()
            ->where('facility_id', $facilityId)
            ->where('status', RosterEntryStatus::PUBLISHED->value)
            ->with(['staff.person', 'staff.primaryCadre', 'staff.primaryPosition', 'shiftPattern']);

        if ($unitId !== null) {
            $query->where('unit_id', $unitId);
        }

        $query->where(function ($q) use ($currentDate, $yesterdayDate, $currentTime): void {
            // 1. Regular daytime shift today (start_time <= end_time)
            $q->where(function ($sub) use ($currentDate, $currentTime): void {
                $sub->where('date', $currentDate)
                    ->whereColumn('start_time', '<=', 'end_time')
                    ->where('start_time', '<=', $currentTime)
                    ->where('end_time', '>=', $currentTime);
            })
            // 2. Overnight shift starting today (start_time > end_time)
                ->orWhere(function ($sub) use ($currentDate, $currentTime): void {
                    $sub->where('date', $currentDate)
                        ->whereColumn('start_time', '>', 'end_time')
                        ->where('start_time', '<=', $currentTime);
                })
            // 3. Overnight shift starting yesterday that runs into today
                ->orWhere(function ($sub) use ($yesterdayDate, $currentTime): void {
                    $sub->where('date', $yesterdayDate)
                        ->whereColumn('start_time', '>', 'end_time')
                        ->where('end_time', '>=', $currentTime);
                });
        });

        return array_values($query
            ->get()
            ->map(function (RosterEntry $entry): OnShiftStaffSummary {
                $staff = $entry->staff;
                $shiftPattern = $entry->shiftPattern;

                if ($staff === null || $shiftPattern === null) {
                    throw new \LogicException(sprintf(
                        'RosterEntry [%s] has missing staff or shiftPattern relationship.',
                        $entry->id
                    ));
                }

                $person = $staff->person;
                $fullName = $person !== null
                    ? trim("{$person->first_name} ".($person->middle_name ? "{$person->middle_name} " : '').$person->last_name)
                    : '';

                return new OnShiftStaffSummary(
                    staffId: $entry->staff_id,
                    staffNumber: $staff->staff_number,
                    fullName: $fullName,
                    cadreName: $staff->primaryCadre?->name,
                    positionTitle: $staff->primaryPosition?->title,
                    facilityId: $entry->facility_id,
                    departmentId: $entry->department_id,
                    unitId: $entry->unit_id,
                    shiftPatternId: $entry->shift_pattern_id,
                    shiftCode: $shiftPattern->code,
                    startTime: (string) $entry->start_time,
                    endTime: (string) $entry->end_time,
                    isOvernight: (bool) $shiftPattern->is_overnight,
                    date: $entry->date->toDateString(),
                );
            })
            ->all());
    }

    public function futurePublishedCount(string $staffId, ?CarbonImmutable $fromDate = null): int
    {
        $from = $fromDate ?? CarbonImmutable::now();

        return RosterEntry::query()
            ->where('staff_id', $staffId)
            ->where('status', RosterEntryStatus::PUBLISHED->value)
            ->where('date', '>=', $from->toDateString())
            ->count();
    }

    private function toSummary(RosterEntry $entry): RosterEntrySummary
    {
        $staff = $entry->staff;
        $person = $staff?->person;
        $staffName = $person !== null
            ? trim("{$person->first_name} ".($person->middle_name ? "{$person->middle_name} " : '').$person->last_name)
            : null;
        $shiftPattern = $entry->shiftPattern;

        return new RosterEntrySummary(
            rosterEntryId: $entry->id,
            tenantId: $entry->tenant_id,
            facilityId: $entry->facility_id,
            departmentId: $entry->department_id,
            unitId: $entry->unit_id,
            staffId: $entry->staff_id,
            staffNumber: $staff?->staff_number,
            staffName: $staffName,
            shiftPatternId: $entry->shift_pattern_id,
            shiftCode: $shiftPattern?->code,
            shiftName: $shiftPattern?->name,
            date: $entry->date->toDateString(),
            startTime: (string) $entry->start_time,
            endTime: (string) $entry->end_time,
            status: $entry->status->value,
            cancellationReason: $entry->cancellation_reason,
            notes: $entry->notes,
            version: $entry->version,
        );
    }
}
