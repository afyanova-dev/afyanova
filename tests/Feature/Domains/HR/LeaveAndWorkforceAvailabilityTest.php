<?php

declare(strict_types=1);

use App\Domains\HR\Application\Command\ApproveLeave;
use App\Domains\HR\Application\Command\CancelLeave;
use App\Domains\HR\Application\Command\ConfirmLeaveTaken;
use App\Domains\HR\Application\Command\DefineLeaveType;
use App\Domains\HR\Application\Command\DefineShiftPattern;
use App\Domains\HR\Application\Command\IngestAttendanceEvent;
use App\Domains\HR\Application\Command\PostLeaveLedgerEntry;
use App\Domains\HR\Application\Command\PublishRoster;
use App\Domains\HR\Application\Command\RequestLeave;
use App\Domains\HR\Application\Command\ScheduleRosterEntry;
use App\Domains\HR\Application\Query\GetLeaveBalance;
use App\Domains\HR\Application\Query\GetLeaveCalendar;
use App\Domains\HR\Application\Query\GetLeaveLedger;
use App\Domains\HR\Application\Query\GetLeaveTypes;
use App\Domains\HR\Application\Query\GetStaffLeaveBalances;
use App\Domains\HR\Application\Query\GetWorkforceAvailability;
use App\Domains\HR\Contracts\Dto\LeaveBalanceSummary;
use App\Domains\HR\Contracts\Dto\LeaveTypeSummary;
use App\Domains\HR\Contracts\Dto\WorkforceAvailabilitySummary;
use App\Domains\HR\Contracts\Event\LeaveApproved;
use App\Domains\HR\Contracts\Event\LeaveCancelled;
use App\Domains\HR\Contracts\Event\LeaveLedgerEntryPosted;
use App\Domains\HR\Contracts\Event\LeaveTaken;
use App\Domains\HR\Contracts\Event\LeaveTypeDefined;
use App\Domains\HR\Contracts\HRApi;
use App\Domains\HR\Domain\Exception\DuplicateLeaveTypeCodeException;
use App\Domains\HR\Domain\Exception\InsufficientLeaveBalanceException;
use App\Domains\HR\Domain\Exception\OverlappingLeaveRequestException;
use App\Domains\HR\Domain\Exception\RequesterCannotApproveOwnLeaveException;
use App\Domains\HR\Domain\ValueObject\AttendanceDirection;
use App\Domains\HR\Domain\ValueObject\AttendanceSource;
use App\Domains\HR\Domain\ValueObject\LeaveLedgerKind;
use App\Domains\HR\Domain\ValueObject\LeaveRequestStatus;
use App\Domains\HR\Domain\ValueObject\StaffStatus;
use App\Domains\HR\Domain\ValueObject\WorkforceAvailabilityStatus;
use App\Models\User;
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

/**
 * Creates a second user in the same tenant with specified permissions, assigned to the same facility.
 *
 * @param  list<string>  $permissions
 * @return array{user: User, role_id: string}
 */
function makeSecondUser(string $tenantId, string $facilityId, array $permissions): array
{
    $user = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);

    $roleId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $user, $roleId, $facilityId, $permissions): void {
        DB::table('idn_roles')->insert([
            'id' => $roleId, 'tenant_id' => $tenantId, 'key' => 'supervisor_role_'.Str::random(4), 'name' => 'Supervisor',
            'status' => 'ACTIVE', 'scope_type' => 'FACILITY', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($permissions as $key) {
            $permId = DB::table('idn_permissions')->where('key', $key)->value('id')
                ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                    'id' => $id, 'key' => $key, 'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                    'created_at' => now(), 'updated_at' => now(),
                ]));

            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
                'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('idn_staff_identities')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $user->id,
            'is_staff' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
            'user_id' => $user->id, 'role_id' => $roleId, 'facility_id' => $facilityId,
            'status' => 'ACTIVE', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    return ['user' => $user, 'role_id' => $roleId];
}

it('defines leave types with outbox events and enforces code uniqueness per tenant', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $typeId = (string) Str::uuid();

    TenantContextScope::run($tenantId, $facilityId, function () use ($typeId): void {
        $this->bus->dispatch(new DefineLeaveType(
            code: 'ANNUAL-2026',
            name: 'Annual Paid Leave',
            isPaid: true,
            accrualRatePerYear: 21.0,
            maxBalance: 30.0,
            allowNegative: false,
            carryOverLimit: 10.0,
            leaveTypeId: $typeId,
        ));
    });

    Tenancy::run($tenantId, function () use ($typeId, $tenantId): void {
        $row = DB::table('hr_leave_types')->where('id', $typeId)->first();
        expect($row)->not->toBeNull()
            ->and($row->code)->toBe('ANNUAL-2026')
            ->and($row->name)->toBe('Annual Paid Leave')
            ->and((bool) $row->is_paid)->toBeTrue()
            ->and((float) $row->accrual_rate_per_year)->toBe(21.0)
            ->and((float) $row->max_balance)->toBe(30.0)
            ->and((bool) $row->allow_negative)->toBeFalse()
            ->and((float) $row->carry_over_limit)->toBe(10.0);

        // Outbox event
        $outbox = DB::table('shared_outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('event_class', LeaveTypeDefined::class)
            ->latest('created_at')
            ->first();
        expect($outbox)->not->toBeNull();
    });

    // Enforce duplicate code rejection per tenant
    expect(function () use ($tenantId, $facilityId): void {
        TenantContextScope::run($tenantId, $facilityId, function (): void {
            $this->bus->dispatch(new DefineLeaveType(
                code: 'ANNUAL-2026',
                name: 'Another Annual Leave',
            ));
        });
    })->toThrow(DuplicateLeaveTypeCodeException::class);

    // Query leave types
    $types = TenantContextScope::run($tenantId, $facilityId, fn (): array => $this->queryBus->dispatch(new GetLeaveTypes));
    expect($types)->toBeArray()->not->toBeEmpty();
    expect($types[0])->toBeInstanceOf(LeaveTypeSummary::class);
});

it('posts append-only leave ledger entries and derives balances deterministically (Invariant 7.9)', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);
    $typeId = defineLeaveTypeHelper($tenantId, code: 'ANNUAL-LEDGER', accrualRate: 24.0);

    // 1. Post OPENING balance of 10 days
    $entry1Id = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $typeId, $entry1Id, $fx): void {
        $this->bus->dispatch(new PostLeaveLedgerEntry(
            staffId: $staffId,
            leaveTypeId: $typeId,
            kind: LeaveLedgerKind::OPENING,
            delta: 10.0,
            effectiveDate: CarbonImmutable::parse('2026-01-01'),
            postedBy: $fx['user']->id,
            reason: 'Opening balance from 2025',
            ledgerEntryId: $entry1Id,
        ));
    });

    // 2. Post ACCRUAL of 2 days
    $entry2Id = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $typeId, $entry2Id, $fx): void {
        $this->bus->dispatch(new PostLeaveLedgerEntry(
            staffId: $staffId,
            leaveTypeId: $typeId,
            kind: LeaveLedgerKind::ACCRUAL,
            delta: 2.0,
            effectiveDate: CarbonImmutable::parse('2026-01-31'),
            postedBy: $fx['user']->id,
            reason: 'January accrual',
            ledgerEntryId: $entry2Id,
        ));
    });

    Tenancy::run($tenantId, function () use ($entry1Id, $entry2Id, $staffId, $tenantId): void {
        $row = DB::table('hr_leave_ledger_entries')->where('id', $entry1Id)->first();
        expect($row)->not->toBeNull()
            ->and($row->staff_id)->toBe($staffId)
            ->and($row->kind)->toBe('OPENING')
            ->and((float) $row->delta)->toBe(10.0);

        $outboxEvents = DB::table('shared_outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('event_class', LeaveLedgerEntryPosted::class)
            ->get();
        expect($outboxEvents)->toHaveCount(2);
        $entryIds = $outboxEvents->map(fn ($ev) => json_decode((string) $ev->payload, true)['ledgerEntryId'])->all();
        expect($entryIds)->toContain($entry1Id, $entry2Id);
    });

    // Verify derived balance is exactly 12.0 (10.0 + 2.0)
    /** @var LeaveBalanceSummary $balance */
    $balance = TenantContextScope::run($tenantId, $facilityId, fn (): LeaveBalanceSummary => $this->queryBus->dispatch(new GetLeaveBalance($staffId, $typeId)));
    expect($balance->accrued)->toBe(12.0)
        ->and($balance->taken)->toBe(0.0)
        ->and($balance->balance)->toBe(12.0);

    // Query ledger list
    $entries = TenantContextScope::run($tenantId, $facilityId, fn (): array => $this->queryBus->dispatch(new GetLeaveLedger($staffId, $typeId)));
    expect($entries)->toHaveCount(2);

    // Query all balances for staff
    $allBalances = TenantContextScope::run($tenantId, $facilityId, fn (): array => $this->queryBus->dispatch(new GetStaffLeaveBalances($staffId)));
    expect($allBalances)->toBeArray()->not->toBeEmpty();
});

it('rejects leave request when balance is insufficient unless negative balance allowed (Invariant 7.9)', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);
    // Type with allow_negative = false
    $typeId = defineLeaveTypeHelper($tenantId, code: 'STRICT-ANNUAL', allowNegative: false);

    // Ledger has only 3 days
    postLeaveLedgerEntryHelper($tenantId, $staffId, $typeId, LeaveLedgerKind::OPENING, 3.0, '2026-01-01', $fx['user']->id);

    // Requesting 5 days should be rejected with InsufficientLeaveBalanceException
    expect(function () use ($tenantId, $facilityId, $staffId, $typeId): void {
        requestLeaveHelper($tenantId, $facilityId, $staffId, $typeId, '2026-02-01', '2026-02-05', 5.0, 'Holiday');
    })->toThrow(InsufficientLeaveBalanceException::class);

    // Type with allow_negative = true
    $typeIdNegative = defineLeaveTypeHelper($tenantId, code: 'SICK-FLEX', allowNegative: true);
    // Requesting 5 days with zero balance should succeed
    $requestId = requestLeaveHelper($tenantId, $facilityId, $staffId, $typeIdNegative, '2026-02-01', '2026-02-05', 5.0, 'Medical leave');
    expect($requestId)->toBeString();
});

it('enforces separation of duties: requester cannot approve their own leave request (AGENTS §19)', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    // Create staff member and link to $fx['user']
    $staffId = onboardStaffHelper($tenantId, firstName: 'Supervisor', lastName: 'User', facilityId: $facilityId);
    linkStaffUserHelper($tenantId, $staffId, (string) $fx['user']->id, $facilityId);

    $typeId = defineLeaveTypeHelper($tenantId, code: 'ANNUAL-DUTY');
    postLeaveLedgerEntryHelper($tenantId, $staffId, $typeId, LeaveLedgerKind::OPENING, 10.0, '2026-01-01', $fx['user']->id);

    // Staff member requests leave
    $requestId = requestLeaveHelper($tenantId, $facilityId, $staffId, $typeId, '2026-03-01', '2026-03-03', 3.0, 'Conference');

    // The same user ($fx['user']) attempts to approve their own leave request -> must fail!
    expect(function () use ($tenantId, $facilityId, $requestId, $fx): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($requestId, $fx): void {
            $this->bus->dispatch(new ApproveLeave($requestId, $fx['user']->id));
        });
    })->toThrow(RequesterCannotApproveOwnLeaveException::class);
});

it('approves leave, atomically posts TAKEN ledger entry, and updates derived balance', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    // Staff member
    $staffId = onboardStaffHelper($tenantId, firstName: 'Alice', lastName: 'Nurse', facilityId: $facilityId);

    // Supervisor user (different user in same tenant)
    $supervisor = makeSecondUser($tenantId, $facilityId, [
        'hr.access',
        'hr.leave.approve',
        'hr.staff.read',
        'hr.staff.manage',
    ]);

    $typeId = defineLeaveTypeHelper($tenantId, code: 'ANNUAL-APP');
    postLeaveLedgerEntryHelper($tenantId, $staffId, $typeId, LeaveLedgerKind::OPENING, 10.0, '2026-01-01', $fx['user']->id);

    $requestId = requestLeaveHelper($tenantId, $facilityId, $staffId, $typeId, '2026-04-01', '2026-04-05', 5.0, 'Vacation');

    // Supervisor approves
    test()->actingAs($supervisor['user']);
    TenantContextScope::run($tenantId, $facilityId, function () use ($requestId, $supervisor): void {
        $this->bus->dispatch(new ApproveLeave($requestId, $supervisor['user']->id));
    });

    Tenancy::run($tenantId, function () use ($requestId, $staffId, $tenantId): void {
        $request = DB::table('hr_leave_requests')->where('id', $requestId)->first();
        expect($request)->not->toBeNull()
            ->and($request->status)->toBe(LeaveRequestStatus::APPROVED->value)
            ->and($request->reviewer_id)->not->toBeNull();

        // Atomically created TAKEN entry in ledger
        $takenEntry = DB::table('hr_leave_ledger_entries')
            ->where('staff_id', $staffId)
            ->where('kind', LeaveLedgerKind::TAKEN->value)
            ->first();
        expect($takenEntry)->not->toBeNull()
            ->and((float) $takenEntry->delta)->toBe(-5.0)
            ->and($takenEntry->source_ref)->toBe($requestId);

        // Outbox event
        $outbox = DB::table('shared_outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('event_class', LeaveApproved::class)
            ->latest('created_at')
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['leaveRequestId'])->toBe($requestId);
    });

    // Derived balance is now 5.0 (10.0 - 5.0)
    test()->actingAs($fx['user']);
    /** @var LeaveBalanceSummary $balance */
    $balance = TenantContextScope::run($tenantId, $facilityId, fn (): LeaveBalanceSummary => $this->queryBus->dispatch(new GetLeaveBalance($staffId, $typeId)));
    expect($balance->balance)->toBe(5.0)
        ->and($balance->taken)->toBe(5.0);
});

it('rejects overlapping leave requests for the same staff member (Invariant 7.12)', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);
    $typeId = defineLeaveTypeHelper($tenantId, code: 'ANNUAL-OVERLAP');
    postLeaveLedgerEntryHelper($tenantId, $staffId, $typeId, LeaveLedgerKind::OPENING, 20.0, '2026-01-01', $fx['user']->id);

    // First request: 2026-05-10 to 2026-05-20
    requestLeaveHelper($tenantId, $facilityId, $staffId, $typeId, '2026-05-10', '2026-05-20', 10.0, 'Trip');

    // Overlapping request: 2026-05-15 to 2026-05-25 -> must throw OverlappingLeaveRequestException
    expect(function () use ($tenantId, $facilityId, $staffId, $typeId): void {
        requestLeaveHelper($tenantId, $facilityId, $staffId, $typeId, '2026-05-15', '2026-05-25', 10.0, 'Overlap 1');
    })->toThrow(OverlappingLeaveRequestException::class);

    // Overlapping request: 2026-05-05 to 2026-05-12 -> must throw OverlappingLeaveRequestException
    expect(function () use ($tenantId, $facilityId, $staffId, $typeId): void {
        requestLeaveHelper($tenantId, $facilityId, $staffId, $typeId, '2026-05-05', '2026-05-12', 7.0, 'Overlap 2');
    })->toThrow(OverlappingLeaveRequestException::class);

    // Non-overlapping request: 2026-05-22 to 2026-05-25 -> succeeds!
    $req2 = requestLeaveHelper($tenantId, $facilityId, $staffId, $typeId, '2026-05-22', '2026-05-25', 3.0, 'Non-overlap');
    expect($req2)->toBeString();
});

it('cancels approved leave and restores balance via compensating ADJUSTMENT ledger entry', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);
    $typeId = defineLeaveTypeHelper($tenantId, code: 'ANNUAL-CANCEL');
    postLeaveLedgerEntryHelper($tenantId, $staffId, $typeId, LeaveLedgerKind::OPENING, 10.0, '2026-01-01', $fx['user']->id);

    $requestId = requestLeaveHelper($tenantId, $facilityId, $staffId, $typeId, '2026-06-01', '2026-06-04', 4.0, 'Trip');

    // Approve leave (using same user for simplicity — staff not linked to user so no separation of duties violation)
    TenantContextScope::run($tenantId, $facilityId, function () use ($requestId, $fx): void {
        $this->bus->dispatch(new ApproveLeave($requestId, $fx['user']->id));
    });

    // Balance after approval is 6.0 (10.0 - 4.0)
    /** @var LeaveBalanceSummary $balanceBeforeCancel */
    $balanceBeforeCancel = TenantContextScope::run($tenantId, $facilityId, fn (): LeaveBalanceSummary => $this->queryBus->dispatch(new GetLeaveBalance($staffId, $typeId)));
    expect($balanceBeforeCancel->balance)->toBe(6.0);

    // Cancel approved leave
    TenantContextScope::run($tenantId, $facilityId, function () use ($requestId, $fx): void {
        $this->bus->dispatch(new CancelLeave($requestId, 'Trip cancelled due to operational exigency', $fx['user']->id));
    });

    Tenancy::run($tenantId, function () use ($requestId, $staffId, $tenantId): void {
        $req = DB::table('hr_leave_requests')->where('id', $requestId)->first();
        expect($req->status)->toBe(LeaveRequestStatus::CANCELLED->value)
            ->and($req->cancellation_reason)->toBe('Trip cancelled due to operational exigency');

        // Compensating ADJUSTMENT entry in ledger
        $adjEntry = DB::table('hr_leave_ledger_entries')
            ->where('staff_id', $staffId)
            ->where('kind', LeaveLedgerKind::ADJUSTMENT->value)
            ->first();
        expect($adjEntry)->not->toBeNull()
            ->and((float) $adjEntry->delta)->toBe(4.0)
            ->and($adjEntry->source_ref)->toBe($requestId);

        $outbox = DB::table('shared_outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('event_class', LeaveCancelled::class)
            ->latest('created_at')
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['leaveRequestId'])->toBe($requestId);
    });

    // Balance after cancellation is restored back to 10.0!
    /** @var LeaveBalanceSummary $balanceAfterCancel */
    $balanceAfterCancel = TenantContextScope::run($tenantId, $facilityId, fn (): LeaveBalanceSummary => $this->queryBus->dispatch(new GetLeaveBalance($staffId, $typeId)));
    expect($balanceAfterCancel->balance)->toBe(10.0);
});

it('confirms leave taken and queries facility leave calendar', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staffId = onboardStaffHelper($tenantId, facilityId: $facilityId);
    $typeId = defineLeaveTypeHelper($tenantId, code: 'ANNUAL-CAL');
    postLeaveLedgerEntryHelper($tenantId, $staffId, $typeId, LeaveLedgerKind::OPENING, 10.0, '2026-01-01', $fx['user']->id);

    $requestId = requestLeaveHelper($tenantId, $facilityId, $staffId, $typeId, '2026-07-01', '2026-07-05', 5.0, 'Holiday');

    // Approve
    TenantContextScope::run($tenantId, $facilityId, function () use ($requestId, $fx): void {
        $this->bus->dispatch(new ApproveLeave($requestId, $fx['user']->id));
    });

    // Confirm taken
    TenantContextScope::run($tenantId, $facilityId, function () use ($requestId): void {
        $this->bus->dispatch(new ConfirmLeaveTaken($requestId));
    });

    Tenancy::run($tenantId, function () use ($requestId, $tenantId): void {
        $req = DB::table('hr_leave_requests')->where('id', $requestId)->first();
        expect($req->status)->toBe(LeaveRequestStatus::TAKEN->value);

        $outbox = DB::table('shared_outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('event_class', LeaveTaken::class)
            ->latest('created_at')
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['leaveRequestId'])->toBe($requestId);
    });

    // Query Leave Calendar
    $calendar = TenantContextScope::run($tenantId, $facilityId, fn (): array => $this->queryBus->dispatch(new GetLeaveCalendar(
        facilityId: $facilityId,
        startDate: CarbonImmutable::parse('2026-07-01'),
        endDate: CarbonImmutable::parse('2026-07-31'),
    )));
    expect($calendar)->toHaveCount(1)
        ->and($calendar[0]->leaveRequestId)->toBe($requestId)
        ->and($calendar[0]->status)->toBe('TAKEN')
        ->and($calendar[0]->days)->toBe(5.0);
});

it('derives workforce availability combining Roster, Approved Leave, and Attendance (Invariant 7.10)', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    // 4 Staff members with organizational assignments
    $staff1 = onboardStaffHelper($tenantId, firstName: 'On', lastName: 'Leave', facilityId: $facilityId);
    $staff2 = onboardStaffHelper($tenantId, firstName: 'Clocked', lastName: 'In', facilityId: $facilityId);
    $staff3 = onboardStaffHelper($tenantId, firstName: 'Scheduled', lastName: 'NotClocked', facilityId: $facilityId);
    $staff4 = onboardStaffHelper($tenantId, firstName: 'Off', lastName: 'Duty', facilityId: $facilityId);

    // Assign all to facility
    foreach ([$staff1, $staff2, $staff3, $staff4] as $sId) {
        assignStaffHelper($tenantId, $sId, $facilityId, isPrimary: true);
    }

    $atTime = CarbonImmutable::parse('2026-08-10 10:00:00');

    // 1. Staff 1: Has approved leave covering 2026-08-10 -> ON_LEAVE
    $leaveType = defineLeaveTypeHelper($tenantId, code: 'AVAIL-ANNUAL');
    postLeaveLedgerEntryHelper($tenantId, $staff1, $leaveType, LeaveLedgerKind::OPENING, 10.0, '2026-01-01', $fx['user']->id);
    $leaveReq = requestLeaveHelper($tenantId, $facilityId, $staff1, $leaveType, '2026-08-08', '2026-08-12', 5.0, 'Summer vacation');
    TenantContextScope::run($tenantId, $facilityId, fn () => $this->bus->dispatch(new ApproveLeave($leaveReq, $fx['user']->id)));

    // 2. Staff 2: Clocked in with OPEN session -> ON_SHIFT
    TenantContextScope::run($tenantId, $facilityId, function () use ($staff2, $facilityId, $atTime): void {
        $this->bus->dispatch(new IngestAttendanceEvent(
            facilityId: $facilityId,
            staffId: $staff2,
            source: AttendanceSource::DEVICE,
            direction: AttendanceDirection::IN,
            occurredAt: $atTime->subHours(2),
        ));
    });

    // 3. Staff 3: Published roster covering 10:00, but NOT clocked in -> SCHEDULED_NOT_CLOCKED_IN
    $shiftPatternId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($shiftPatternId, $facilityId): void {
        $this->bus->dispatch(new DefineShiftPattern(
            facilityId: $facilityId,
            code: 'DAY-SHFT',
            name: 'Day Shift 8-16',
            startTime: '08:00:00',
            endTime: '16:00:00',
            shiftPatternId: $shiftPatternId,
        ));
    });
    $rosterId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($rosterId, $facilityId, $staff3, $shiftPatternId): void {
        $this->bus->dispatch(new ScheduleRosterEntry(
            facilityId: $facilityId,
            staffId: $staff3,
            shiftPatternId: $shiftPatternId,
            date: CarbonImmutable::parse('2026-08-10'),
            rosterEntryId: $rosterId,
        ));
        $this->bus->dispatch(new PublishRoster($facilityId, '2026-08-10', '2026-08-10'));
    });

    // 4. Staff 4: No leave, no roster, not clocked in -> OFF_DUTY

    // Query workforce availability
    /** @var list<WorkforceAvailabilitySummary> $availability */
    $availability = TenantContextScope::run($tenantId, $facilityId, fn (): array => $this->queryBus->dispatch(new GetWorkforceAvailability(
        facilityId: $facilityId,
        atTime: $atTime,
    )));

    $byStaff = collect($availability)->keyBy('staffId');

    expect($byStaff->get($staff1)->status)->toBe(WorkforceAvailabilityStatus::ON_LEAVE->value)
        ->and($byStaff->get($staff2)->status)->toBe(WorkforceAvailabilityStatus::ON_SHIFT->value)
        ->and($byStaff->get($staff3)->status)->toBe(WorkforceAvailabilityStatus::SCHEDULED_NOT_CLOCKED_IN->value)
        ->and($byStaff->get($staff4)->status)->toBe(WorkforceAvailabilityStatus::OFF_DUTY->value);

    // Invariant 7.6 verification: Staff employment status in hr_staff is strictly ACTIVE (never contains ON_LEAVE)
    Tenancy::run($tenantId, function () use ($staff1): void {
        $staffRow = DB::table('hr_staff')->where('id', $staff1)->first();
        expect($staffRow->status)->toBe(StaffStatus::ACTIVE->value);
    });
});

it('verifies cross-domain contract port HRApi availability and on-shift queries', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $staff1 = onboardStaffHelper($tenantId, firstName: 'Dr', lastName: 'Smith', facilityId: $facilityId);
    $staff2 = onboardStaffHelper($tenantId, firstName: 'Dr', lastName: 'Jones', facilityId: $facilityId);
    assignStaffHelper($tenantId, $staff1, $facilityId, isPrimary: true);
    assignStaffHelper($tenantId, $staff2, $facilityId, isPrimary: true);

    $typeId = defineLeaveTypeHelper($tenantId, code: 'ANNUAL-HRAPI');
    postLeaveLedgerEntryHelper($tenantId, $staff1, $typeId, LeaveLedgerKind::OPENING, 10.0, '2026-01-01', $fx['user']->id);

    $leaveReq = requestLeaveHelper($tenantId, $facilityId, $staff1, $typeId, '2026-09-01', '2026-09-05', 5.0);
    TenantContextScope::run($tenantId, $facilityId, fn () => $this->bus->dispatch(new ApproveLeave($leaveReq, $fx['user']->id)));

    /** @var HRApi $hrApi */
    $hrApi = app(HRApi::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($hrApi, $staff1, $staff2, $facilityId): void {
        $checkDate = CarbonImmutable::parse('2026-09-03 10:00:00');

        // isStaffOnLeave
        expect($hrApi->isStaffOnLeave($staff1, $checkDate))->toBeTrue()
            ->and($hrApi->isStaffOnLeave($staff2, $checkDate))->toBeFalse();

        // isStaffAvailable
        expect($hrApi->isStaffAvailable($staff1, $checkDate))->toBeFalse()
            ->and($hrApi->isStaffAvailable($staff2, $checkDate))->toBeTrue();

        // getWorkforceAvailability
        $avail = $hrApi->getWorkforceAvailability($facilityId, atTime: $checkDate);
        expect($avail)->toBeArray()->not->toBeEmpty();
    });
});

it('enforces multi-tenant RLS isolation across leave types, ledgers, and requests', function (): void {
    $fxA = hrFixture();
    $fxB = hrFixture();

    $tenantA = $fxA['tenant']->id;
    $tenantB = $fxB['tenant']->id;
    $facA = $fxA['facility']->id;
    $facB = $fxB['facility']->id;

    // Act as Tenant A user to create Tenant A's records
    test()->actingAs($fxA['user']);

    $staffA = onboardStaffHelper($tenantA, facilityId: $facA);
    $typeA = defineLeaveTypeHelper($tenantA, code: 'ANNUAL-A', facilityId: $facA);
    $entryA = postLeaveLedgerEntryHelper($tenantA, $staffA, $typeA, LeaveLedgerKind::OPENING, 10.0, '2026-01-01', $fxA['user']->id, facilityId: $facA);
    $reqA = requestLeaveHelper($tenantA, $facA, $staffA, $typeA, '2026-10-01', '2026-10-03', 3.0);

    // Tenant B cannot see Tenant A's leave types, ledger entries, or requests via direct queries under RLS
    Tenancy::run($tenantB, function () use ($typeA, $entryA, $reqA): void {
        expect(DB::table('hr_leave_types')->where('id', $typeA)->exists())->toBeFalse()
            ->and(DB::table('hr_leave_ledger_entries')->where('id', $entryA)->exists())->toBeFalse()
            ->and(DB::table('hr_leave_requests')->where('id', $reqA)->exists())->toBeFalse();
    });
});

it('enforces RBAC permission gates on leave management commands', function (): void {
    // Fixture with NO leave permissions
    $fx = hrFixture(permissions: [
        'hr.access',
        'hr.staff.read',
    ]);
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    // 1. DefineLeaveType fails without hr.leave_type.manage
    expect(function () use ($tenantId, $facilityId): void {
        TenantContextScope::run($tenantId, $facilityId, function (): void {
            $this->bus->dispatch(new DefineLeaveType(
                code: 'NO-PERM-ANNUAL',
                name: 'Forbidden Leave',
            ));
        });
    })->toThrow(AuthorizationException::class);

    // 2. PostLeaveLedgerEntry fails without hr.leave_ledger.post
    expect(function () use ($tenantId, $facilityId, $fx): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($fx): void {
            $this->bus->dispatch(new PostLeaveLedgerEntry(
                staffId: (string) Str::uuid(),
                leaveTypeId: (string) Str::uuid(),
                kind: LeaveLedgerKind::OPENING,
                delta: 10.0,
                effectiveDate: CarbonImmutable::now(),
                postedBy: $fx['user']->id,
            ));
        });
    })->toThrow(AuthorizationException::class);

    // 3. RequestLeave fails without hr.leave.request
    expect(function () use ($tenantId, $facilityId): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId): void {
            $this->bus->dispatch(new RequestLeave(
                facilityId: $facilityId,
                staffId: (string) Str::uuid(),
                leaveTypeId: (string) Str::uuid(),
                startDate: CarbonImmutable::now(),
                endDate: CarbonImmutable::now()->addDays(2),
                days: 2.0,
            ));
        });
    })->toThrow(AuthorizationException::class);
});
