<?php

declare(strict_types=1);

use App\Domains\HR\Application\Command\CancelShift;
use App\Domains\HR\Application\Command\DefineShiftPattern;
use App\Domains\HR\Application\Command\OnboardStaff;
use App\Domains\HR\Application\Command\PublishRoster;
use App\Domains\HR\Application\Command\ScheduleRosterEntry;
use App\Domains\HR\Application\Command\SwapShift;
use App\Domains\HR\Application\Command\TerminateStaff;
use App\Domains\HR\Application\Command\UpdateShiftPattern;
use App\Domains\HR\Contracts\Event\RosterEntryScheduled;
use App\Domains\HR\Contracts\Event\RosterPublished;
use App\Domains\HR\Contracts\Event\ShiftCancelled;
use App\Domains\HR\Contracts\Event\ShiftPatternDefined;
use App\Domains\HR\Contracts\Event\ShiftPatternUpdated;
use App\Domains\HR\Contracts\Event\ShiftSwapped;
use App\Domains\HR\Domain\Exception\DuplicateRosterSlotException;
use App\Domains\HR\Domain\Exception\DuplicateShiftPatternCodeException;
use App\Domains\HR\Domain\Exception\InvalidRosterStatusTransitionException;
use App\Domains\HR\Domain\Exception\ShiftPatternNotFoundException;
use App\Domains\HR\Domain\Exception\ShiftSwapMismatchException;
use App\Domains\HR\Domain\Exception\StaffHasFutureRosterEntriesException;
use App\Domains\HR\Domain\Exception\StaffNotEligibleForRosterException;
use App\Domains\HR\Domain\RosterEntryReader;
use App\Domains\HR\Domain\ShiftPatternReader;
use App\Domains\HR\Domain\ValueObject\RosterEntryStatus;
use App\Domains\HR\Domain\ValueObject\StaffStatus;
use App\Domains\Organization\Domain\Model\Facility;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/hr.php';

beforeEach(function (): void {
    $this->bus = app(CommandBus::class);
    $this->queryBus = app(QueryBus::class);
});

it('defines and updates shift pattern templates with outbox events', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $patternId = (string) Str::uuid();

    // 1. DefineShiftPattern
    TenantContextScope::run($tenantId, $facilityId, function () use ($patternId, $facilityId): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'DAY',
            name: 'Standard Day Shift',
            startTime: '08:00',
            endTime: '17:00',
            breakMinutes: 60,
            isOvernight: false,
            shiftPatternId: $patternId,
        ));
    });

    Tenancy::run($tenantId, function () use ($patternId): void {
        $row = DB::table('hr_shift_patterns')->where('id', $patternId)->first();
        expect($row)->not->toBeNull()
            ->and($row->code)->toBe('DAY')
            ->and($row->name)->toBe('Standard Day Shift')
            ->and($row->break_minutes)->toBe(60)
            ->and($row->is_overnight)->toBeFalse()
            ->and($row->is_active)->toBeTrue();

        // Outbox event ShiftPatternDefined
        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', ShiftPatternDefined::class)
            ->latest('created_at')
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['shiftPatternId'])->toBe($patternId)
            ->and($payload['code'])->toBe('DAY');
    });

    // 2. Query shift pattern
    TenantContextScope::run($tenantId, $facilityId, function () use ($patternId, $facilityId): void {
        $reader = app(ShiftPatternReader::class);
        $summary = $reader->summary($patternId);
        expect($summary->code)->toBe('DAY')
            ->and($summary->breakDurationMinutes)->toBe(60);

        $list = $reader->listByFacility($facilityId);
        expect($list)->toHaveCount(1)
            ->and($list[0]->shiftPatternId)->toBe($patternId);
    });

    // 3. UpdateShiftPattern
    TenantContextScope::run($tenantId, $facilityId, function () use ($patternId): void {
        $this->bus->dispatch(new UpdateShiftPattern(
            shiftPatternId: $patternId,
            name: 'Updated Day Shift',
            startTime: '08:30',
            endTime: '17:30',
            breakMinutes: 45,
            isOvernight: false,
            isActive: true,
        ));
    });

    Tenancy::run($tenantId, function () use ($patternId): void {
        $rowUpdated = DB::table('hr_shift_patterns')->where('id', $patternId)->first();
        expect($rowUpdated->name)->toBe('Updated Day Shift')
            ->and($rowUpdated->break_minutes)->toBe(45);

        // Outbox event ShiftPatternUpdated
        $outboxUpdated = DB::table('shared_outbox_events')
            ->where('event_class', ShiftPatternUpdated::class)
            ->latest('created_at')
            ->first();
        expect($outboxUpdated)->not->toBeNull();
        $payloadUpdated = json_decode((string) $outboxUpdated->payload, true);
        expect($payloadUpdated['shiftPatternId'])->toBe($patternId)
            ->and($payloadUpdated['name'])->toBe('Updated Day Shift');
    });
});

it('rejects duplicate shift pattern code in the same facility but allows it in another facility', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityAId = $fx['facility']->id;

    // Create facility B under same tenant
    $facilityB = Facility::factory()
        ->forTenant($tenantId, $fx['organization']->id)
        ->create();
    $facilityBId = $facilityB->id;

    DB::table('idn_access_assignments')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'user_id' => $fx['user']->id,
        'role_id' => $fx['role_id'],
        'facility_id' => $facilityBId,
        'status' => 'ACTIVE',
        'is_primary' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    TenantContextScope::run($tenantId, $facilityAId, function () use ($facilityAId): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityAId,
            code: 'NIGHT',
            name: 'Night Shift A',
            startTime: '20:00',
            endTime: '08:00',
            breakMinutes: 60,
            isOvernight: true,
        ));
    });

    // Duplicate in Facility A must fail
    expect(function () use ($tenantId, $facilityAId): void {
        TenantContextScope::run($tenantId, $facilityAId, function () use ($facilityAId): void {
            $this->bus->dispatch(new DefineShiftPattern(
                facilityId: $facilityAId,
                code: 'NIGHT',
                name: 'Night Shift Duplicate',
                startTime: '21:00',
                endTime: '07:00',
                breakMinutes: 30,
                isOvernight: true,
            ));
        });
    })->toThrow(DuplicateShiftPatternCodeException::class);

    // Same code in Facility B is allowed
    TenantContextScope::run($tenantId, $facilityBId, function () use ($facilityBId): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityBId,
            code: 'NIGHT',
            name: 'Night Shift B',
            startTime: '20:00',
            endTime: '08:00',
            breakMinutes: 60,
            isOvernight: true,
        ));
    });

    Tenancy::run($tenantId, function (): void {
        expect(DB::table('hr_shift_patterns')->where('code', 'NIGHT')->count())->toBe(2);
    });
});

it('schedules draft roster entries, publishes them in batch, and emits domain events', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Marcus', 'A', 'Rashford');
    $staffId = createStaffHelper($tenantId, $personId);

    // Activate staff
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        $this->bus->dispatch(new OnboardStaff($staffId, '2026-01-01'));
    });

    // Define shift pattern
    $patternId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($patternId, $facilityId): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'MORNING',
            name: 'Morning Ward Shift',
            startTime: '07:00',
            endTime: '15:00',
            breakMinutes: 30,
            isOvernight: false,
            shiftPatternId: $patternId,
        ));
    });

    // 1. Schedule draft entry for 2026-12-01
    $entryId1 = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use (
        $entryId1, $facilityId, $staffId, $patternId
    ): void {
        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $staffId,
            shiftPatternId: $patternId,
            date: '2026-12-01',
            notes: 'Pediatric ICU coverage',
            rosterEntryId: $entryId1,
        ));
    });

    Tenancy::run($tenantId, function () use ($entryId1, $staffId): void {
        $entry1 = DB::table('hr_roster_entries')->where('id', $entryId1)->first();
        expect($entry1)->not->toBeNull()
            ->and($entry1->status)->toBe(RosterEntryStatus::DRAFT->value)
            ->and($entry1->start_time)->toBe('07:00:00')
            ->and($entry1->end_time)->toBe('15:00:00')
            ->and($entry1->notes)->toBe('Pediatric ICU coverage');

        // Outbox event RosterEntryScheduled
        $outboxScheduled = DB::table('shared_outbox_events')
            ->where('event_class', RosterEntryScheduled::class)
            ->latest('created_at')
            ->first();
        expect($outboxScheduled)->not->toBeNull();
        $schedPayload = json_decode((string) $outboxScheduled->payload, true);
        expect($schedPayload['rosterEntryId'])->toBe($entryId1)
            ->and($schedPayload['staffId'])->toBe($staffId);
    });

    // Schedule draft entry for 2026-12-02
    $entryId2 = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use (
        $entryId2, $facilityId, $staffId, $patternId
    ): void {
        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $staffId,
            shiftPatternId: $patternId,
            date: '2026-12-02',
            rosterEntryId: $entryId2,
        ));
    });

    // 2. Publish roster for period 2026-12-01 to 2026-12-07
    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId): void {
        $this->bus->dispatch(new PublishRoster(
            facilityId: $facilityId,
            startDate: '2026-12-01',
            endDate: '2026-12-07',
        ));
    });

    Tenancy::run($tenantId, function () use ($entryId1, $entryId2, $facilityId): void {
        $e1After = DB::table('hr_roster_entries')->where('id', $entryId1)->first();
        $e2After = DB::table('hr_roster_entries')->where('id', $entryId2)->first();
        expect($e1After->status)->toBe(RosterEntryStatus::PUBLISHED->value)
            ->and($e2After->status)->toBe(RosterEntryStatus::PUBLISHED->value);

        // Outbox event RosterPublished
        $outboxPub = DB::table('shared_outbox_events')
            ->where('event_class', RosterPublished::class)
            ->latest('created_at')
            ->first();
        expect($outboxPub)->not->toBeNull();
        $pubPayload = json_decode((string) $outboxPub->payload, true);
        expect($pubPayload['facilityId'])->toBe($facilityId)
            ->and($pubPayload['publishedCount'])->toBe(2)
            ->and($pubPayload['publishedEntryIds'])->toContain($entryId1)
            ->and($pubPayload['publishedEntryIds'])->toContain($entryId2);
    });
});

it('rejects scheduling roster entries for inactive staff or duplicate active slots', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Bruno', 'E', 'Fernandes');
    $staffId = createStaffHelper($tenantId, $personId); // Still in ONBOARDING status

    $patternId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($patternId, $facilityId): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'AFTERNOON',
            name: 'Afternoon Shift',
            startTime: '14:00',
            endTime: '22:00',
            shiftPatternId: $patternId,
        ));
    });

    // 1. Staff not in ACTIVE status cannot be scheduled
    expect(function () use ($tenantId, $facilityId, $staffId, $patternId): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $staffId, $patternId): void {
            $this->bus->dispatch(new ScheduleRosterEntry(
                facilityId: $facilityId,
                staffId: $staffId,
                shiftPatternId: $patternId,
                date: '2026-12-10',
            ));
        });
    })->toThrow(StaffNotEligibleForRosterException::class);

    // Onboard staff to make ACTIVE
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        $this->bus->dispatch(new OnboardStaff($staffId, '2026-01-01'));
    });

    // First scheduling succeeds
    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $staffId, $patternId): void {
        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $staffId,
            shiftPatternId: $patternId,
            date: '2026-12-10',
        ));
    });

    // 2. Duplicate active slot on same staff, date, and shift pattern must fail
    expect(function () use ($tenantId, $facilityId, $staffId, $patternId): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $staffId, $patternId): void {
            $this->bus->dispatch(new ScheduleRosterEntry(
                facilityId: $facilityId,
                staffId: $staffId,
                shiftPatternId: $patternId,
                date: '2026-12-10',
            ));
        });
    })->toThrow(DuplicateRosterSlotException::class);
});

it('swaps shifts atomically between two published roster entries and emits ShiftSwapped', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $p1 = createPersonHelper($tenantId, 'Kobe', 'B', 'Bryant');
    $staff1 = createStaffHelper($tenantId, $p1);
    $p2 = createPersonHelper($tenantId, 'LeBron', 'R', 'James');
    $staff2 = createStaffHelper($tenantId, $p2);

    TenantContextScope::run($tenantId, $facilityId, function () use ($staff1, $staff2): void {
        $this->bus->dispatch(new OnboardStaff($staff1, '2026-01-01'));
        $this->bus->dispatch(new OnboardStaff($staff2, '2026-01-01'));
    });

    $patternDay = (string) Str::uuid();
    $patternNight = (string) Str::uuid();

    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $patternDay, $patternNight): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'SWAP-DAY',
            name: 'Swap Day',
            startTime: '08:00',
            endTime: '16:00',
            shiftPatternId: $patternDay,
        ));
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'SWAP-NIGHT',
            name: 'Swap Night',
            startTime: '20:00',
            endTime: '08:00',
            isOvernight: true,
            shiftPatternId: $patternNight,
        ));
    });

    $entry1Id = (string) Str::uuid();
    $entry2Id = (string) Str::uuid();

    TenantContextScope::run($tenantId, $facilityId, function () use (
        $facilityId, $staff1, $staff2, $patternDay, $patternNight, $entry1Id, $entry2Id
    ): void {
        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $staff1,
            shiftPatternId: $patternDay,
            date: '2026-12-15',
            rosterEntryId: $entry1Id,
        ));
        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $staff2,
            shiftPatternId: $patternNight,
            date: '2026-12-15',
            rosterEntryId: $entry2Id,
        ));
    });

    // Attempt swap when still in DRAFT -> must fail
    expect(function () use ($tenantId, $facilityId, $entry1Id, $entry2Id): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($entry1Id, $entry2Id): void {
            $this->bus->dispatch(new SwapShift($entry1Id, $entry2Id));
        });
    })->toThrow(ShiftSwapMismatchException::class);

    // Publish roster
    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId): void {
        $this->bus->dispatch(new PublishRoster(
            facilityId: $facilityId,
            startDate: '2026-12-15',
            endDate: '2026-12-15',
        ));
    });

    // Swap published shifts
    TenantContextScope::run($tenantId, $facilityId, function () use ($entry1Id, $entry2Id): void {
        $this->bus->dispatch(new SwapShift($entry1Id, $entry2Id));
    });

    Tenancy::run($tenantId, function () use ($entry1Id, $entry2Id, $staff1, $staff2): void {
        $e1Swapped = DB::table('hr_roster_entries')->where('id', $entry1Id)->first();
        $e2Swapped = DB::table('hr_roster_entries')->where('id', $entry2Id)->first();

        // Staff assignments have exchanged!
        expect($e1Swapped->staff_id)->toBe($staff2)
            ->and($e2Swapped->staff_id)->toBe($staff1)
            ->and($e1Swapped->status)->toBe(RosterEntryStatus::PUBLISHED->value)
            ->and($e2Swapped->status)->toBe(RosterEntryStatus::PUBLISHED->value);

        // Outbox event ShiftSwapped
        $outboxSwap = DB::table('shared_outbox_events')
            ->where('event_class', ShiftSwapped::class)
            ->latest('created_at')
            ->first();
        expect($outboxSwap)->not->toBeNull();
        $swapPayload = json_decode((string) $outboxSwap->payload, true);
        expect($swapPayload['entryAId'])->toBe($entry1Id)
            ->and($swapPayload['staffAId'])->toBe($staff1)
            ->and($swapPayload['entryBId'])->toBe($entry2Id)
            ->and($swapPayload['staffBId'])->toBe($staff2);
    });
});

it('cancels scheduled shifts with reason and emits ShiftCancelled', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Luka', 'D', 'Doncic');
    $staffId = createStaffHelper($tenantId, $personId);

    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        $this->bus->dispatch(new OnboardStaff($staffId, '2026-01-01'));
    });

    $patternId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($patternId, $facilityId): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'CANCEL-TEST',
            name: 'Cancel Test Shift',
            startTime: '09:00',
            endTime: '17:00',
            shiftPatternId: $patternId,
        ));
    });

    $entryId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $staffId, $patternId, $entryId): void {
        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $staffId,
            shiftPatternId: $patternId,
            date: '2026-12-20',
            rosterEntryId: $entryId,
        ));
    });

    // Cancel shift
    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId): void {
        $this->bus->dispatch(new CancelShift(
            rosterEntryId: $entryId,
            reason: 'Staff sick leave granted',
        ));
    });

    Tenancy::run($tenantId, function () use ($entryId): void {
        $cancelled = DB::table('hr_roster_entries')->where('id', $entryId)->first();
        expect($cancelled->status)->toBe(RosterEntryStatus::CANCELLED->value)
            ->and($cancelled->cancellation_reason)->toBe('Staff sick leave granted');

        // Outbox event ShiftCancelled
        $outboxCancel = DB::table('shared_outbox_events')
            ->where('event_class', ShiftCancelled::class)
            ->latest('created_at')
            ->first();
        expect($outboxCancel)->not->toBeNull();
        $cancelPayload = json_decode((string) $outboxCancel->payload, true);
        expect($cancelPayload['rosterEntryId'])->toBe($entryId)
            ->and($cancelPayload['cancellationReason'])->toBe('Staff sick leave granted');
    });

    // Cannot cancel already cancelled shift
    expect(function () use ($tenantId, $facilityId, $entryId): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($entryId): void {
            $this->bus->dispatch(new CancelShift(
                rosterEntryId: $entryId,
                reason: 'Second cancellation',
            ));
        });
    })->toThrow(InvalidRosterStatusTransitionException::class);
});

it('executes GetOnShiftNow query accurately for daytime and overnight shifts', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $pDay = createPersonHelper($tenantId, 'Day', 'D', 'Worker');
    $sDay = createStaffHelper($tenantId, $pDay);

    $pNight = createPersonHelper($tenantId, 'Night', 'N', 'Worker');
    $sNight = createStaffHelper($tenantId, $pNight);

    TenantContextScope::run($tenantId, $facilityId, function () use ($sDay, $sNight): void {
        $this->bus->dispatch(new OnboardStaff($sDay, '2026-01-01'));
        $this->bus->dispatch(new OnboardStaff($sNight, '2026-01-01'));
    });

    $patDay = (string) Str::uuid();
    $patNight = (string) Str::uuid();

    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $patDay, $patNight): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'DAY-DUTY',
            name: 'Day Duty',
            startTime: '08:00',
            endTime: '16:00',
            shiftPatternId: $patDay,
        ));
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'NIGHT-DUTY',
            name: 'Night Duty',
            startTime: '20:00',
            endTime: '06:00',
            isOvernight: true,
            shiftPatternId: $patNight,
        ));
    });

    $eDayId = (string) Str::uuid();
    $eNightId = (string) Str::uuid();

    TenantContextScope::run($tenantId, $facilityId, function () use (
        $facilityId, $sDay, $sNight, $patDay, $patNight, $eDayId, $eNightId
    ): void {
        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $sDay,
            shiftPatternId: $patDay,
            date: '2026-12-25',
            rosterEntryId: $eDayId,
        ));
        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $sNight,
            shiftPatternId: $patNight,
            date: '2026-12-25',
            rosterEntryId: $eNightId,
        ));
        $this->bus->dispatch(new PublishRoster(
            facilityId: $facilityId,
            startDate: '2026-12-25',
            endDate: '2026-12-25',
        ));
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $sDay, $sNight): void {
        $reader = app(RosterEntryReader::class);

        // 1. At 10:00 on 2026-12-25 -> Day worker is ON SHIFT, night worker is NOT
        $at10am = CarbonImmutable::parse('2026-12-25 10:00:00');
        $onShift10am = $reader->getOnShiftNow($facilityId, atTime: $at10am);
        expect($onShift10am)->toHaveCount(1)
            ->and($onShift10am[0]->staffId)->toBe($sDay);

        // 2. At 22:00 on 2026-12-25 -> Night worker is ON SHIFT, day worker is NOT
        $at10pm = CarbonImmutable::parse('2026-12-25 22:00:00');
        $onShift10pm = $reader->getOnShiftNow($facilityId, atTime: $at10pm);
        expect($onShift10pm)->toHaveCount(1)
            ->and($onShift10pm[0]->staffId)->toBe($sNight);

        // 3. At 03:00 on 2026-12-26 (next morning, cross-midnight) -> Night worker from 2026-12-25 is STILL ON SHIFT!
        $at3amNextDay = CarbonImmutable::parse('2026-12-26 03:00:00');
        $onShift3am = $reader->getOnShiftNow($facilityId, atTime: $at3amNextDay);
        expect($onShift3am)->toHaveCount(1)
            ->and($onShift3am[0]->staffId)->toBe($sNight)
            ->and($onShift3am[0]->isOvernight)->toBeTrue();

        // 4. At 07:00 on 2026-12-26 -> Night shift has ended at 06:00, no one on shift
        $at7amNextDay = CarbonImmutable::parse('2026-12-26 07:00:00');
        $onShift7am = $reader->getOnShiftNow($facilityId, atTime: $at7amNextDay);
        expect($onShift7am)->toBeEmpty();
    });
});

it('blocks staff termination when future published roster entries exist (ADR-206 F1)', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Giannis', 'A', 'Antetokounmpo');
    $staffId = createStaffHelper($tenantId, $personId);

    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        $this->bus->dispatch(new OnboardStaff($staffId, '2026-01-01'));
    });

    $patternId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($patternId, $facilityId): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'FUTURE-SHIFT',
            name: 'Future Shift',
            startTime: '08:00',
            endTime: '16:00',
            shiftPatternId: $patternId,
        ));
    });

    $entryId = (string) Str::uuid();
    $futureDate = CarbonImmutable::now()->addDays(5)->toDateString();

    TenantContextScope::run($tenantId, $facilityId, function () use (
        $facilityId, $staffId, $patternId, $entryId, $futureDate
    ): void {
        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $staffId,
            shiftPatternId: $patternId,
            date: $futureDate,
            rosterEntryId: $entryId,
        ));
        $this->bus->dispatch(new PublishRoster(
            facilityId: $facilityId,
            startDate: $futureDate,
            endDate: $futureDate,
        ));
    });

    // Attempt TerminateStaff must fail because future published roster entry exists
    expect(function () use ($tenantId, $facilityId, $staffId): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
            $this->bus->dispatch(new TerminateStaff(
                staffId: $staffId,
                terminatedDate: CarbonImmutable::now()->toDateString(),
                reason: 'Resigned',
            ));
        });
    })->toThrow(StaffHasFutureRosterEntriesException::class);

    // Cancel future shift
    TenantContextScope::run($tenantId, $facilityId, function () use ($entryId): void {
        $this->bus->dispatch(new CancelShift(
            rosterEntryId: $entryId,
            reason: 'Shift cancelled prior to resignation',
        ));
    });

    // Now termination succeeds
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        $this->bus->dispatch(new TerminateStaff(
            staffId: $staffId,
            terminatedDate: CarbonImmutable::now()->toDateString(),
            reason: 'Resigned',
        ));
    });

    Tenancy::run($tenantId, function () use ($staffId): void {
        $terminated = DB::table('hr_staff')->where('id', $staffId)->first();
        expect($terminated->status)->toBe(StaffStatus::TERMINATED->value);
    });
});

it('enforces tenant isolation across shift patterns and roster entries', function (): void {
    $fxA = hrFixture();
    $tenantA = $fxA['tenant']->id;
    $facilityA = $fxA['facility']->id;

    $fxB = makeTenantWithUser([
        'hr.access',
        'hr.staff.read',
        'hr.shift_pattern.manage',
        'hr.roster.manage',
    ]);
    $tenantB = $fxB['tenant']->id;
    $facilityB = $fxB['facility']->id;

    $patternAId = (string) Str::uuid();

    TenantContextScope::run($tenantA, $facilityA, function () use ($patternAId, $facilityA): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityA,
            code: 'TENANT-A-SHIFT',
            name: 'Tenant A Exclusive Shift',
            startTime: '08:00',
            endTime: '17:00',
            shiftPatternId: $patternAId,
        ));
    });

    // Tenant B cannot see Tenant A's shift patterns
    test()->actingAs($fxB['user']);
    TenantContextScope::run($tenantB, $facilityB, function () use ($facilityB, $patternAId): void {
        $reader = app(ShiftPatternReader::class);
        $patterns = $reader->listByFacility($facilityB);
        expect($patterns)->toBeEmpty();

        expect(fn () => $reader->summary($patternAId))
            ->toThrow(ShiftPatternNotFoundException::class);
    });
});

it('enforces RBAC permissions on shift pattern and roster management', function (): void {
    // Fixture with read-only permissions
    $fx = hrFixture(['hr.access', 'hr.staff.read']);
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    expect(function () use ($tenantId, $facilityId): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId): void {
            $this->bus->dispatch(new DefineShiftPattern(
                facilityId: $facilityId,
                code: 'UNAUTHORIZED',
                name: 'Unauthorized Shift',
                startTime: '08:00',
                endTime: '16:00',
            ));
        });
    })->toThrow(AuthorizationException::class);
});
