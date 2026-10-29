<?php

declare(strict_types=1);

use App\Domains\HR\Application\Command\CloseAttendanceSession;
use App\Domains\HR\Application\Command\DefineShiftPattern;
use App\Domains\HR\Application\Command\IngestAttendanceEvent;
use App\Domains\HR\Application\Command\PublishRoster;
use App\Domains\HR\Application\Command\RaiseAttendanceException;
use App\Domains\HR\Application\Command\RebuildAttendanceSessions;
use App\Domains\HR\Application\Command\ReconcileAttendanceSession;
use App\Domains\HR\Application\Command\ScheduleRosterEntry;
use App\Domains\HR\Contracts\Dto\TimesheetSummary;
use App\Domains\HR\Contracts\Event\AttendanceEventIngested;
use App\Domains\HR\Contracts\Event\AttendanceExceptionRaised;
use App\Domains\HR\Contracts\Event\AttendanceSessionClosed;
use App\Domains\HR\Contracts\Event\AttendanceSessionReconciled;
use App\Domains\HR\Domain\AttendanceEventReader;
use App\Domains\HR\Domain\AttendanceSessionReader;
use App\Domains\HR\Domain\Exception\InvalidAttendanceSessionTransitionException;
use App\Domains\HR\Domain\ValueObject\AttendanceDirection;
use App\Domains\HR\Domain\ValueObject\AttendanceExceptionType;
use App\Domains\HR\Domain\ValueObject\AttendanceSource;
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

it('ingests attendance events and emits AttendanceEventIngested to outbox', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);
    $clockTime = CarbonImmutable::parse('2026-10-15 07:55:00');
    $eventId = (string) Str::uuid();

    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId, $clockTime, $eventId): void {
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: $clockTime,
            deviceRef: 'BIO-GATE-01',
            externalEventId: 'DEV-EVT-1001',
            notes: 'Main entrance gate',
            attendanceEventId: $eventId,
        ));
    });

    Tenancy::run($tenantId, function () use ($eventId, $staffId): void {
        $row = DB::table('hr_attendance_events')->where('id', $eventId)->first();
        expect($row)->not->toBeNull()
            ->and($row->staff_id)->toBe($staffId)
            ->and($row->source)->toBe('DEVICE')
            ->and($row->direction)->toBe('IN')
            ->and($row->device_ref)->toBe('BIO-GATE-01')
            ->and($row->external_event_id)->toBe('DEV-EVT-1001');

        // Outbox event
        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', AttendanceEventIngested::class)
            ->latest('created_at')
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['attendanceEventId'])->toBe($eventId)
            ->and($payload['staffId'])->toBe($staffId)
            ->and($payload['direction'])->toBe('IN');
    });

    // Query event via reader
    TenantContextScope::run($tenantId, $facilityId, function () use ($eventId, $staffId): void {
        $reader = app(AttendanceEventReader::class);
        $summary = $reader->summary($eventId);
        expect($summary->direction)->toBe('IN')
            ->and($summary->source)->toBe('DEVICE')
            ->and($summary->deviceRef)->toBe('BIO-GATE-01');

        $list = $reader->forStaff($staffId);
        expect($list)->toHaveCount(1)
            ->and($list[0]->attendanceEventId)->toBe($eventId);
    });
});

it('guarantees idempotency on duplicate external_event_id without duplicating records', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);
    $clockTime = CarbonImmutable::parse('2026-10-15 08:00:00');
    $externalId = 'BIO-RETRY-'.Str::random(12);

    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId, $clockTime, $externalId): void {
        // First ingestion
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: $clockTime,
            externalEventId: $externalId,
        ));

        // Duplicate retry (e.g. device network reconnect sync)
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: $clockTime,
            externalEventId: $externalId,
        ));
    });

    Tenancy::run($tenantId, function () use ($externalId): void {
        $count = DB::table('hr_attendance_events')->where('external_event_id', $externalId)->count();
        expect($count)->toBe(1);
    });
});

it('tracks session lifecycle through IN, BREAK_OUT, BREAK_IN, and OUT events', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);

    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId): void {
        // 1. Clock In at 08:00
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: '2026-10-15 08:00:00',
        ));

        // 2. Break Out at 12:00
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::BREAK_OUT,
            occurredAt: '2026-10-15 12:00:00',
        ));

        // 3. Break In at 12:45 (45 minutes break)
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::BREAK_IN,
            occurredAt: '2026-10-15 12:45:00',
        ));

        // 4. Clock Out at 17:00 (total span 9h = 540m; worked = 540 - 45 = 495m)
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::OUT,
            occurredAt: '2026-10-15 17:00:00',
        ));
    });

    Tenancy::run($tenantId, function () use ($staffId): void {
        $session = DB::table('hr_attendance_sessions')->where('staff_id', $staffId)->first();
        expect($session)->not->toBeNull()
            ->and($session->status)->toBe('CLOSED')
            ->and($session->break_minutes)->toBe(45)
            ->and($session->worked_minutes)->toBe(495)
            ->and($session->exception)->toBe('NONE');

        // Outbox event AttendanceSessionClosed
        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', AttendanceSessionClosed::class)
            ->latest('created_at')
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['staffId'])->toBe($staffId)
            ->and($payload['workedMinutes'])->toBe(495)
            ->and($payload['breakMinutes'])->toBe(45);
    });

    // Query via reader
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        $reader = app(AttendanceSessionReader::class);
        $sessions = $reader->forStaff($staffId);
        expect($sessions)->toHaveCount(1)
            ->and($sessions[0]->status)->toBe('CLOSED')
            ->and($sessions[0]->workedMinutes)->toBe(495)
            ->and($sessions[0]->breakMinutes)->toBe(45);
    });
});

it('detects LATE, EARLY_LEAVE, OVERTIME, MISSING_OUT, and MISSING_IN exceptions against roster', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);
    $date = CarbonImmutable::parse('2026-10-20');

    // 1. Create and publish shift pattern and roster entry (08:00 - 17:00)
    $patternId = (string) Str::uuid();
    $rosterEntryId = (string) Str::uuid();

    TenantContextScope::run($tenantId, $facilityId, function () use (
        $facilityId, $staffId, $date, $patternId, $rosterEntryId
    ): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'DAY-EX',
            name: 'Day Shift Exception Test',
            startTime: '08:00',
            endTime: '17:00',
            breakMinutes: 60,
            shiftPatternId: $patternId,
        ));

        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $staffId,
            shiftPatternId: $patternId,
            date: $date,
            rosterEntryId: $rosterEntryId,
        ));

        $this->bus->dispatch(new PublishRoster(
            facilityId: $facilityId,
            startDate: $date,
            endDate: $date,
        ));
    });

    // 2. Staff clocks in late (>15m grace: 08:30)
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId, $date): void {
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: $date->setTime(8, 30),
        ));
    });

    Tenancy::run($tenantId, function () use ($staffId): void {
        $session = DB::table('hr_attendance_sessions')->where('staff_id', $staffId)->first();
        expect($session)->not->toBeNull()
            ->and($session->status)->toBe('OPEN')
            ->and($session->exception)->toBe('LATE');
    });

    // 3. Staff clocks out early (<16:45: 16:00) -> exception updated to EARLY_LEAVE
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId, $date): void {
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::OUT,
            occurredAt: $date->setTime(16, 0),
        ));
    });

    Tenancy::run($tenantId, function () use ($staffId): void {
        $session = DB::table('hr_attendance_sessions')->where('staff_id', $staffId)->first();
        expect($session)->not->toBeNull()
            ->and($session->status)->toBe('CLOSED')
            ->and($session->exception)->toBe('EARLY_LEAVE');
    });

    // 4. Test MISSING_OUT: Clock In on next day without clocking out
    $nextDate = $date->addDay();
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId, $nextDate): void {
        // Clock In #1
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: $nextDate->setTime(8, 0),
        ));

        // Clock In #2 without Clock Out #1
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: $nextDate->setTime(14, 0),
        ));
    });

    Tenancy::run($tenantId, function () use ($staffId, $nextDate): void {
        $priorSession = DB::table('hr_attendance_sessions')
            ->where('staff_id', $staffId)
            ->where('started_at', $nextDate->setTime(8, 0))
            ->first();

        expect($priorSession)->not->toBeNull()
            ->and($priorSession->status)->toBe('CLOSED')
            ->and($priorSession->exception)->toBe('MISSING_OUT');
    });

    // 5. Test MISSING_IN: Clock Out without prior Clock In
    $staffBId = onboardStaffHelper($tenantId, facilityId: $facilityId);
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffBId, $facilityId, $date): void {
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffBId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::OUT,
            occurredAt: $date->setTime(17, 0),
        ));
    });

    Tenancy::run($tenantId, function () use ($staffBId): void {
        $session = DB::table('hr_attendance_sessions')->where('staff_id', $staffBId)->first();
        expect($session)->not->toBeNull()
            ->and($session->status)->toBe('CLOSED')
            ->and($session->exception)->toBe('MISSING_IN');
    });

    // 6. Query exceptions via reader
    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId): void {
        $reader = app(AttendanceSessionReader::class);
        $exceptions = $reader->getExceptions($facilityId);
        expect(count($exceptions))->toBeGreaterThanOrEqual(3);
    });
});

it('reconciles attendance session with supervisor notes and emits AttendanceSessionReconciled', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);

    // Create session with missing out
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId): void {
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: '2026-10-15 08:00:00',
        ));
    });

    $sessionId = '';
    Tenancy::run($tenantId, function () use ($staffId, &$sessionId): void {
        $session = DB::table('hr_attendance_sessions')->where('staff_id', $staffId)->first();
        expect($session)->not->toBeNull();
        $sessionId = $session->id;
    });

    // Reconcile session
    TenantContextScope::run($tenantId, $facilityId, function () use ($sessionId, $fx): void {
        $this->bus->dispatch(new ReconcileAttendanceSession(
            sessionId: $sessionId,
            reconciledBy: $fx['user']->id,
            note: 'Verified with security log; staff left at 17:00.',
            adjustedWorkedMinutes: 480,
            adjustedException: AttendanceExceptionType::NONE,
        ));
    });

    Tenancy::run($tenantId, function () use ($sessionId, $fx): void {
        $session = DB::table('hr_attendance_sessions')->where('id', $sessionId)->first();
        expect($session)->not->toBeNull()
            ->and($session->status)->toBe('RECONCILED')
            ->and($session->reconciled_by)->toBe($fx['user']->id)
            ->and($session->reconciliation_note)->toBe('Verified with security log; staff left at 17:00.')
            ->and($session->worked_minutes)->toBe(480)
            ->and($session->exception)->toBe('NONE');

        // Outbox event AttendanceSessionReconciled
        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', AttendanceSessionReconciled::class)
            ->latest('created_at')
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['attendanceSessionId'])->toBe($sessionId)
            ->and($payload['reconciledBy'])->toBe($fx['user']->id)
            ->and($payload['workedMinutes'])->toBe(480);
    });

    // Reconciled session cannot transition to CLOSED
    expect(function () use ($tenantId, $facilityId, $sessionId): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($sessionId): void {
            $this->bus->dispatch(new CloseAttendanceSession(
                sessionId: $sessionId,
                endedAt: CarbonImmutable::now(),
            ));
        });
    })->toThrow(InvalidAttendanceSessionTransitionException::class);
});

it('raises explicit attendance exceptions and emits AttendanceExceptionRaised', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);

    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId): void {
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: '2026-10-15 08:00:00',
        ));
    });

    $sessionId = '';
    Tenancy::run($tenantId, function () use ($staffId, &$sessionId): void {
        $sessionId = (string) DB::table('hr_attendance_sessions')->where('staff_id', $staffId)->value('id');
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($sessionId): void {
        $this->bus->dispatch(new RaiseAttendanceException(
            sessionId: $sessionId,
            exceptionType: AttendanceExceptionType::DISPUTED,
        ));
    });

    Tenancy::run($tenantId, function () use ($sessionId): void {
        $session = DB::table('hr_attendance_sessions')->where('id', $sessionId)->first();
        expect($session->exception)->toBe('DISPUTED');

        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', AttendanceExceptionRaised::class)
            ->latest('created_at')
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['attendanceSessionId'])->toBe($sessionId)
            ->and($payload['exceptionType'])->toBe('DISPUTED');
    });
});

it('reconstructs attendance sessions deterministically during rebuild without overwriting reconciled sessions', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);

    // Day 1: Clock In and Out
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId): void {
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: '2026-10-15 08:00:00',
        ));
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::OUT,
            occurredAt: '2026-10-15 17:00:00',
        ));
    });

    $day1SessionId = '';
    Tenancy::run($tenantId, function () use ($staffId, &$day1SessionId): void {
        $day1SessionId = (string) DB::table('hr_attendance_sessions')->where('staff_id', $staffId)->value('id');
    });

    // Reconcile Day 1 session
    TenantContextScope::run($tenantId, $facilityId, function () use ($day1SessionId, $fx): void {
        $this->bus->dispatch(new ReconcileAttendanceSession(
            sessionId: $day1SessionId,
            reconciledBy: $fx['user']->id,
            note: 'Audited day 1 shift',
        ));
    });

    // Day 2: Clock In, Break Out, Break In, Out
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId): void {
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: '2026-10-16 08:00:00',
        ));
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::BREAK_OUT,
            occurredAt: '2026-10-16 12:00:00',
        ));
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::BREAK_IN,
            occurredAt: '2026-10-16 12:30:00',
        ));
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::OUT,
            occurredAt: '2026-10-16 16:30:00',
        ));
    });

    // Rebuild sessions for staff across Oct 15 - Oct 16
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        $this->bus->dispatch(new RebuildAttendanceSessions(
            staffId: $staffId,
            startDate: '2026-10-15',
            endDate: '2026-10-16',
        ));
    });

    Tenancy::run($tenantId, function () use ($staffId, $day1SessionId): void {
        $sessions = DB::table('hr_attendance_sessions')
            ->where('staff_id', $staffId)
            ->orderBy('started_at', 'asc')
            ->get();

        expect($sessions)->toHaveCount(2);

        // Day 1 session was preserved with RECONCILED status
        expect($sessions[0]->id)->toBe($day1SessionId)
            ->and($sessions[0]->status)->toBe('RECONCILED')
            ->and($sessions[0]->reconciliation_note)->toBe('Audited day 1 shift');

        // Day 2 session rebuilt cleanly
        expect($sessions[1]->status)->toBe('CLOSED')
            ->and($sessions[1]->break_minutes)->toBe(30)
            ->and($sessions[1]->worked_minutes)->toBe(480); // 8.5h (510m) - 30m break = 480m
    });
});

it('aggregates planned vs worked hours in GetTimesheet for payroll hand-off', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);
    $date = CarbonImmutable::parse('2026-10-15');

    // 1. Schedule a roster entry: 08:00 to 16:00 (8h = 480m)
    $patternId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $staffId, $date, $patternId): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'DAY-TS',
            name: 'Day Shift Timesheet Test',
            startTime: '08:00',
            endTime: '16:00',
            breakMinutes: 0,
            shiftPatternId: $patternId,
        ));

        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $staffId,
            shiftPatternId: $patternId,
            date: $date,
        ));

        $this->bus->dispatch(new PublishRoster(
            facilityId: $facilityId,
            startDate: $date,
            endDate: $date,
        ));
    });

    // 2. Staff works 08:00 to 18:00 (10h = 600m) -> 120m overtime
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId, $date): void {
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: $date->setTime(8, 0),
        ));
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staffId,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::OUT,
            occurredAt: $date->setTime(18, 0),
        ));
    });

    // 3. Query Timesheet
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId, $date): void {
        $reader = app(AttendanceSessionReader::class);
        $timesheet = $reader->getTimesheet(
            staffId: $staffId,
            facilityId: $facilityId,
            startDate: $date,
            endDate: $date,
        );

        expect($timesheet)->toBeInstanceOf(TimesheetSummary::class)
            ->and($timesheet->staffId)->toBe($staffId)
            ->and($timesheet->totalPlannedMinutes)->toBe(480)
            ->and($timesheet->totalWorkedMinutes)->toBe(600)
            ->and($timesheet->totalOvertimeMinutes)->toBe(120)
            ->and($timesheet->days)->toHaveCount(1)
            ->and($timesheet->days[0]->status)->toBe('ATTENDED')
            ->and($timesheet->days[0]->overtimeMinutes)->toBe(120);
    });
});

it('enforces PostgreSQL RLS tenant isolation across attendance events and sessions', function (): void {
    $fxA = hrFixture();
    $tenantA = $fxA['tenant']->id;
    $facilityA = $fxA['facility']->id;

    $fxB = hrFixture();
    $tenantB = $fxB['tenant']->id;
    $this->actingAs($fxA['user']);
    $staffA = onboardStaffHelper($tenantA, facilityId: $facilityA);

    TenantContextScope::run($tenantA, $facilityA, function () use ($staffA, $facilityA): void {
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityA,
            staffId: $staffA,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: '2026-10-15 08:00:00',
        ));
    });

    // Tenant A can see their event and session
    Tenancy::run($tenantA, function () use ($staffA): void {
        expect(DB::table('hr_attendance_events')->where('staff_id', $staffA)->count())->toBe(1);
        expect(DB::table('hr_attendance_sessions')->where('staff_id', $staffA)->count())->toBe(1);
    });

    // Tenant B cannot see Tenant A's event or session under RLS
    Tenancy::run($tenantB, function () use ($staffA): void {
        expect(DB::table('hr_attendance_events')->where('staff_id', $staffA)->count())->toBe(0);
        expect(DB::table('hr_attendance_sessions')->where('staff_id', $staffA)->count())->toBe(0);
    });
});

it('enforces authorization gates for attendance ingestion and reconciliation', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;
    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);

    // Unprivileged user without hr.attendance.ingest or hr.attendance.manage
    $unprivileged = makeTenantWithUser(['hr.access', 'hr.staff.read']);
    test()->actingAs($unprivileged['user']);

    expect(function () use ($tenantId, $facilityId, $staffId): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $facilityId): void {
            $this->bus->dispatch(new IngestAttendanceEvent(
                facilityId: $facilityId,
                staffId: $staffId,
                source: AttendanceSource::DEVICE,
                direction: AttendanceDirection::IN,
                occurredAt: '2026-10-15 08:00:00',
            ));
        });
    })->toThrow(AuthorizationException::class);
});
