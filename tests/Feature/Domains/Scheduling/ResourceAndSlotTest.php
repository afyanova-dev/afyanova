<?php

declare(strict_types=1);

use App\Domains\HR\Application\Command\ApproveLeave;
use App\Domains\Scheduling\Application\Command\DefineAppointmentType;
use App\Domains\Scheduling\Application\Command\RegisterSchedulableResource;
use App\Domains\Scheduling\Application\Query\GetAppointmentTypes;
use App\Domains\Scheduling\Application\Query\GetAvailableSlots;
use App\Domains\Scheduling\Application\Query\GetResourceSchedule;
use App\Domains\Scheduling\Application\Query\GetSchedulableResources;
use App\Domains\Scheduling\Contracts\Dto\AppointmentTypeSummary;
use App\Domains\Scheduling\Contracts\Dto\SchedulableResourceSummary;
use App\Domains\Scheduling\Contracts\Dto\ScheduleSummary;
use App\Domains\Scheduling\Contracts\Dto\SlotSummary;
use App\Domains\Scheduling\Domain\Exception\DuplicateAppointmentTypeCodeException;
use App\Domains\Scheduling\Domain\Exception\DuplicateSchedulableResourceException;
use App\Domains\Scheduling\Domain\Exception\InvalidScheduleException;
use App\Domains\Scheduling\Domain\Exception\ProviderUnavailableException;
use App\Domains\Scheduling\Domain\Exception\SlotNotAvailableException;
use App\Domains\Scheduling\Domain\Model\Slot;
use App\Domains\Scheduling\Domain\ValueObject\ResourceType;
use App\Domains\Scheduling\Domain\ValueObject\SlotStatus;
use App\Domains\Scheduling\Infrastructure\Sequence\AppointmentNumberSequence;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/scheduling.php';
require_once __DIR__.'/../../../../tests/Support/hr.php';

beforeEach(function (): void {
    $this->bus = app(CommandBus::class);
    $this->queryBus = app(QueryBus::class);
});

it('defines appointment types and prevents duplicates within tenant', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;

    $typeId = defineAppointmentTypeHelper(
        tenantId: $tenantId,
        code: 'CAR_CONS',
        name: 'Cardiology Consultation',
        durationMinutes: 45,
        bufferBeforeMinutes: 5,
        bufferAfterMinutes: 10,
    );

    expect($typeId)->toBeString();

    // Re-defining the same code in the same tenant must throw DuplicateAppointmentTypeCodeException
    expect(fn () => defineAppointmentTypeHelper(
        tenantId: $tenantId,
        code: 'CAR_CONS',
        name: 'Duplicate Cardiology',
    ))->toThrow(DuplicateAppointmentTypeCodeException::class);
});

it('lists appointment types via QueryBus with optional facility filtering', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    defineAppointmentTypeHelper(
        tenantId: $tenantId,
        code: 'TYPE_GLOBAL',
        name: 'Global Type',
        facilityId: null,
    );

    defineAppointmentTypeHelper(
        tenantId: $tenantId,
        code: 'TYPE_FACILITY',
        name: 'Facility Specific Type',
        facilityId: $facilityId,
    );

    /** @var list<AppointmentTypeSummary> $allTypes */
    $allTypes = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(new GetAppointmentTypes));

    expect(count($allTypes))->toBeGreaterThanOrEqual(2);

    /** @var list<AppointmentTypeSummary> $facilityTypes */
    $facilityTypes = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(new GetAppointmentTypes(facilityId: $facilityId)));

    expect(count($facilityTypes))->toBeGreaterThanOrEqual(2);
    $codes = array_map(fn (AppointmentTypeSummary $t): string => $t->code, $facilityTypes);
    expect($codes)->toContain('TYPE_GLOBAL', 'TYPE_FACILITY');
});

it('registers schedulable resources for providers after validating against HR', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    // Create staff in HR
    $personId = createPersonHelper($tenantId, 'Alice', null, 'Doctor');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);

    $resourceId = registerSchedulableResourceHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceRef: $staffId,
        name: 'Dr. Alice Doctor',
        resourceType: ResourceType::PROVIDER,
    );

    expect($resourceId)->toBeString();

    // Query schedulable resources
    /** @var list<SchedulableResourceSummary> $resources */
    $resources = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(
        new GetSchedulableResources(facilityId: $facilityId, resourceType: ResourceType::PROVIDER->value)
    ));

    expect($resources)->toHaveCount(1);
    expect($resources[0]->resourceRef)->toBe($staffId);
    expect($resources[0]->name)->toBe('Dr. Alice Doctor');
});

it('rejects provider registration if staff does not exist in HR', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $nonExistentStaffId = (string) Str::uuid();

    expect(fn () => registerSchedulableResourceHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceRef: $nonExistentStaffId,
        name: 'Ghost Provider',
        resourceType: ResourceType::PROVIDER,
    ))->toThrow(ProviderUnavailableException::class);
});

it('rejects duplicate schedulable resource in same facility', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Bob', null, 'Smith');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);

    registerSchedulableResourceHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceRef: $staffId,
        name: 'Dr. Bob Smith',
        resourceType: ResourceType::PROVIDER,
    );

    expect(fn () => registerSchedulableResourceHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceRef: $staffId,
        name: 'Dr. Bob Duplicate',
        resourceType: ResourceType::PROVIDER,
    ))->toThrow(DuplicateSchedulableResourceException::class);
});

it('registers room and equipment resources', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $roomRef = (string) Str::uuid();
    $equipRef = (string) Str::uuid();

    $roomId = registerSchedulableResourceHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceRef: $roomRef,
        name: 'Consultation Room 101',
        resourceType: ResourceType::ROOM,
    );

    $equipId = registerSchedulableResourceHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceRef: $equipRef,
        name: 'Portable Ultrasound Unit A',
        resourceType: ResourceType::EQUIPMENT,
    );

    expect($roomId)->toBeString();
    expect($equipId)->toBeString();

    /** @var list<SchedulableResourceSummary> $rooms */
    $rooms = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(
        new GetSchedulableResources(facilityId: $facilityId, resourceType: ResourceType::ROOM->value)
    ));
    expect($rooms)->toHaveCount(1);
    expect($rooms[0]->resourceRef)->toBe($roomRef);
});

it('defines weekly recurring schedules and rejects invalid time ranges', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Charlie', null, 'Brown');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $resourceId = registerSchedulableResourceHelper($tenantId, $facilityId, $staffId, 'Dr. Charlie');

    // Valid schedule: Monday 09:00 - 13:00
    $schId = defineScheduleHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        dayOfWeek: 1,
        startTime: '09:00',
        endTime: '13:00',
    );
    expect($schId)->toBeString();

    // Invalid schedule: startTime >= endTime
    expect(fn () => defineScheduleHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        dayOfWeek: 2,
        startTime: '14:00',
        endTime: '10:00',
    ))->toThrow(InvalidScheduleException::class);

    /** @var list<ScheduleSummary> $schedules */
    $schedules = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(
        new GetResourceSchedule(resourceId: $resourceId)
    ));
    expect($schedules)->toHaveCount(1);
    expect($schedules[0]->dayOfWeek)->toBe(1);
    expect($schedules[0]->startTime)->toBe('09:00:00');
    expect($schedules[0]->endTime)->toBe('13:00:00');
});

it('adds schedule exceptions for holidays and blackouts', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Dana', null, 'Scully');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $resourceId = registerSchedulableResourceHelper($tenantId, $facilityId, $staffId, 'Dr. Dana Scully');

    $excId = addScheduleExceptionHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        exceptionDate: '2026-12-25',
        reason: 'Christmas Holiday',
        isAvailable: false,
    );

    expect($excId)->toBeString();
});

it('generates bookable slots according to working schedules', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Fox', null, 'Mulder');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $resourceId = registerSchedulableResourceHelper($tenantId, $facilityId, $staffId, 'Dr. Fox Mulder');

    // Monday (day 1) 09:00 - 11:00 (30 min slots = 4 slots: 09:00, 09:30, 10:00, 10:30)
    defineScheduleHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        dayOfWeek: 1,
        startTime: '09:00',
        endTime: '11:00',
    );

    // Target a date range containing a Monday: 2026-10-05 (Monday) to 2026-10-05
    $monday = CarbonImmutable::parse('2026-10-05');
    expect((int) $monday->dayOfWeekIso)->toBe(1);

    generateSlotsHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        startDate: $monday,
        endDate: $monday,
        slotDurationMinutes: 30,
        capacity: 1,
    );

    /** @var list<SlotSummary> $slots */
    $slots = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(
        new GetAvailableSlots(
            facilityId: $facilityId,
            startDate: $monday,
            endDate: $monday,
            resourceId: $resourceId,
        )
    ));

    expect($slots)->toHaveCount(4);
    expect($slots[0]->status)->toBe(SlotStatus::OPEN->value);
});

it('respects schedule exceptions during slot generation', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Walter', null, 'Skinner');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $resourceId = registerSchedulableResourceHelper($tenantId, $facilityId, $staffId, 'Dr. Walter Skinner');

    // Monday 09:00 - 11:00
    defineScheduleHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        dayOfWeek: 1,
        startTime: '09:00',
        endTime: '11:00',
    );

    $monday = CarbonImmutable::parse('2026-10-12'); // Monday

    // Add exception marking this Monday as unavailable
    addScheduleExceptionHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        exceptionDate: $monday,
        reason: 'Staff training',
        isAvailable: false,
    );

    generateSlotsHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        startDate: $monday,
        endDate: $monday,
        slotDurationMinutes: 30,
    );

    /** @var list<SlotSummary> $slots */
    $slots = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(
        new GetAvailableSlots(
            facilityId: $facilityId,
            startDate: $monday,
            endDate: $monday,
            resourceId: $resourceId,
        )
    ));

    // Zero slots should be generated because of the exception
    expect($slots)->toHaveCount(0);
});

it('skips slot generation when provider is on approved leave via HRApi', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Monica', null, 'Reyes');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $resourceId = registerSchedulableResourceHelper($tenantId, $facilityId, $staffId, 'Dr. Monica Reyes');

    defineScheduleHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        dayOfWeek: 1, // Monday
        startTime: '09:00',
        endTime: '11:00',
    );

    $monday = CarbonImmutable::parse('2026-10-19'); // Monday

    // Submit and approve leave in HR domain for this provider
    $leaveTypeId = defineLeaveTypeHelper($tenantId, code: 'ANNUAL', name: 'Annual Leave', allowNegative: true, facilityId: $facilityId);
    $leaveReqId = requestLeaveHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        staffId: $staffId,
        leaveTypeId: $leaveTypeId,
        startDate: '2026-10-18',
        endDate: '2026-10-20',
        days: 3.0,
        reason: 'Annual Vacation',
    );

    TenantContextScope::run($tenantId, $facilityId, fn () => $this->bus->dispatch(
        new ApproveLeave($leaveReqId, $fx['user']->id)
    ));

    // Slot generation must detect approved leave via HRApi and generate 0 slots for this day
    generateSlotsHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        startDate: $monday,
        endDate: $monday,
        slotDurationMinutes: 30,
    );

    /** @var list<SlotSummary> $slots */
    $slots = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(
        new GetAvailableSlots(
            facilityId: $facilityId,
            startDate: $monday,
            endDate: $monday,
            resourceId: $resourceId,
        )
    ));

    expect($slots)->toHaveCount(0);
});

it('holds an available slot and prevents double-holding', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'John', null, 'Doggett');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $resourceId = registerSchedulableResourceHelper($tenantId, $facilityId, $staffId, 'Dr. John Doggett');

    defineScheduleHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        dayOfWeek: 1,
        startTime: '09:00',
        endTime: '10:00',
    );

    $monday = CarbonImmutable::parse('2026-10-26');
    generateSlotsHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        startDate: $monday,
        endDate: $monday,
        slotDurationMinutes: 30,
    );

    /** @var list<SlotSummary> $slots */
    $slots = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(
        new GetAvailableSlots(
            facilityId: $facilityId,
            startDate: $monday,
            endDate: $monday,
            resourceId: $resourceId,
        )
    ));

    expect($slots)->toHaveCount(2);
    $targetSlot = $slots[0];

    // Hold slot for 15 minutes
    holdSlotHelper($tenantId, $targetSlot->id, heldBy: 'agent_user_1', holdMinutes: 15);

    // Refresh model directly
    $slotModel = TenantContextScope::run($tenantId, $facilityId, fn () => Slot::query()->find($targetSlot->id));
    expect($slotModel)->not->toBeNull();
    expect($slotModel->status)->toBe(SlotStatus::HELD);
    expect($slotModel->held_by)->toBe('agent_user_1');
    expect($slotModel->held_until)->not->toBeNull();

    // Trying to hold an already held slot must throw SlotNotAvailableException
    expect(fn () => holdSlotHelper($tenantId, $targetSlot->id, heldBy: 'agent_user_2', holdMinutes: 15))
        ->toThrow(SlotNotAvailableException::class);
});

it('releases a slot hold back to open', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Alex', null, 'Krycek');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $resourceId = registerSchedulableResourceHelper($tenantId, $facilityId, $staffId, 'Dr. Alex Krycek');

    defineScheduleHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        dayOfWeek: 2, // Tuesday
        startTime: '10:00',
        endTime: '10:30',
    );

    $tuesday = CarbonImmutable::parse('2026-10-27');
    generateSlotsHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        startDate: $tuesday,
        endDate: $tuesday,
        slotDurationMinutes: 30,
    );

    /** @var list<SlotSummary> $slots */
    $slots = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(
        new GetAvailableSlots(facilityId: $facilityId, startDate: $tuesday, endDate: $tuesday)
    ));
    $slotId = $slots[0]->id;

    // Hold and verify
    holdSlotHelper($tenantId, $slotId, heldBy: 'user_a');
    $held = TenantContextScope::run($tenantId, $facilityId, fn () => Slot::query()->find($slotId));
    expect($held?->status)->toBe(SlotStatus::HELD);

    // Release and verify
    releaseSlotHoldHelper($tenantId, $slotId, 'Customer cancelled booking flow');
    $released = TenantContextScope::run($tenantId, $facilityId, fn () => Slot::query()->find($slotId));
    expect($released?->status)->toBe(SlotStatus::OPEN);
    expect($released?->held_by)->toBeNull();
    expect($released?->held_until)->toBeNull();
});

it('queries available slots and handles expired holds', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'William', null, 'Scully');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $resourceId = registerSchedulableResourceHelper($tenantId, $facilityId, $staffId, 'Dr. William Scully');

    defineScheduleHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        dayOfWeek: 3, // Wednesday
        startTime: '14:00',
        endTime: '15:00',
    );

    $wednesday = CarbonImmutable::parse('2026-10-28');
    generateSlotsHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        startDate: $wednesday,
        endDate: $wednesday,
        slotDurationMinutes: 30,
    );

    /** @var list<SlotSummary> $slots */
    $slots = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(
        new GetAvailableSlots(facilityId: $facilityId, startDate: $wednesday, endDate: $wednesday)
    ));
    expect($slots)->toHaveCount(2);

    $slotId = $slots[0]->id;
    holdSlotHelper($tenantId, $slotId, 'held_user');

    // Artificially expire the hold in DB
    TenantContextScope::run($tenantId, $facilityId, fn () => Slot::query()->whereKey($slotId)->update([
        'held_until' => CarbonImmutable::now()->subMinutes(5),
    ]));

    // Query available slots should include the slot whose hold expired
    /** @var list<SlotSummary> $availableAfterExpiry */
    $availableAfterExpiry = TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(
        new GetAvailableSlots(facilityId: $facilityId, startDate: $wednesday, endDate: $wednesday)
    ));
    $ids = array_map(fn (SlotSummary $s): string => $s->id, $availableAfterExpiry);
    expect($ids)->toContain($slotId);

    // A new user can hold this slot since previous hold expired!
    holdSlotHelper($tenantId, $slotId, 'new_user', holdMinutes: 10);
    $reheld = TenantContextScope::run($tenantId, $facilityId, fn () => Slot::query()->find($slotId));
    expect($reheld?->status)->toBe(SlotStatus::HELD);
    expect($reheld?->held_by)->toBe('new_user');
});

it('enforces multi-tenant RLS isolation', function (): void {
    $fxA = schedulingFixture();
    $tenantA = (string) $fxA['tenant']->id;
    $facilityA = (string) $fxA['facility']->id;

    $fxB = schedulingFixture();
    $tenantB = (string) $fxB['tenant']->id;
    $facilityB = (string) $fxB['facility']->id;

    // Define appointment type in Tenant A
    test()->actingAs($fxA['user']);
    $typeAId = defineAppointmentTypeHelper(tenantId: $tenantA, code: 'APT_TENANT_A', name: 'Tenant A Type', scopeFacilityId: $facilityA);

    // Define appointment type in Tenant B with same code (allowed because code is unique per tenant!)
    test()->actingAs($fxB['user']);
    $typeBId = defineAppointmentTypeHelper(tenantId: $tenantB, code: 'APT_TENANT_A', name: 'Tenant B Type', scopeFacilityId: $facilityB);

    expect($typeAId)->not->toBe($typeBId);

    // In Tenant A, only Tenant A types must be visible
    test()->actingAs($fxA['user']);
    /** @var list<AppointmentTypeSummary> $typesA */
    $typesA = TenantContextScope::run($tenantA, $facilityA, fn () => $this->queryBus->dispatch(new GetAppointmentTypes));
    $namesA = array_map(fn (AppointmentTypeSummary $t): string => $t->name, $typesA);
    expect($namesA)->toContain('Tenant A Type');
    expect($namesA)->not->toContain('Tenant B Type');

    // In Tenant B, only Tenant B types must be visible
    test()->actingAs($fxB['user']);
    /** @var list<AppointmentTypeSummary> $typesB */
    $typesB = TenantContextScope::run($tenantB, $facilityB, fn () => $this->queryBus->dispatch(new GetAppointmentTypes));
    $namesB = array_map(fn (AppointmentTypeSummary $t): string => $t->name, $typesB);
    expect($namesB)->toContain('Tenant B Type');
    expect($namesB)->not->toContain('Tenant A Type');
});

it('enforces RBAC permissions on scheduling commands and queries', function (): void {
    // User without any permissions
    $fx = schedulingFixture(permissions: []);
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    // Unauthorized define appointment type
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $this->bus->dispatch(
        new DefineAppointmentType(code: 'NO_PERM', name: 'No Perm')
    )))->toThrow(AuthorizationException::class);

    // Unauthorized register resource
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $this->bus->dispatch(
        new RegisterSchedulableResource(
            facilityId: $facilityId,
            resourceType: ResourceType::ROOM,
            resourceRef: (string) Str::uuid(),
            name: 'Unauthorized Room'
        )
    )))->toThrow(AuthorizationException::class);

    // Unauthorized query
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => $this->queryBus->dispatch(
        new GetAppointmentTypes
    )))->toThrow(AuthorizationException::class);
});

it('allocates monotonic appointment numbers via AppointmentNumberSequence (ADR-115)', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $sequence = app(AppointmentNumberSequence::class);

    $num1 = TenantContextScope::run($tenantId, $facilityId, fn () => $sequence->next($tenantId));
    $num2 = TenantContextScope::run($tenantId, $facilityId, fn () => $sequence->next($tenantId));
    $num3 = TenantContextScope::run($tenantId, $facilityId, fn () => $sequence->next($tenantId));

    expect($num1)->toBe('APT-00000001');
    expect($num2)->toBe('APT-00000002');
    expect($num3)->toBe('APT-00000003');
});

it('rejects schedule definition with inverted effective dates', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Inverted', null, 'Schedule');
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    $resourceId = registerSchedulableResourceHelper($tenantId, $facilityId, $staffId, 'Dr. Inverted');

    // effectiveFrom ('2026-12-31') > effectiveTo ('2026-01-01') must throw InvalidScheduleException
    expect(fn () => defineScheduleHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: $resourceId,
        dayOfWeek: 1,
        startTime: '08:00',
        endTime: '12:00',
        effectiveFrom: '2026-12-31',
        effectiveTo: '2026-01-01',
    ))->toThrow(InvalidScheduleException::class);
});

it('tracks slot booking capacity transitions', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $slot = Slot::create(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: (string) Str::uuid(),
        slotDate: '2026-11-01',
        startTime: '2026-11-01 10:00:00',
        endTime: '2026-11-01 10:30:00',
        capacity: 2,
    );

    expect($slot->status)->toBe(SlotStatus::OPEN);
    expect($slot->booked_count)->toBe(0);

    // Book first seat
    $slot->book();
    expect($slot->booked_count)->toBe(1);
    expect($slot->status)->toBe(SlotStatus::OPEN); // Still open because capacity is 2

    // Book second seat
    $slot->book();
    expect($slot->booked_count)->toBe(2);
    expect($slot->status)->toBe(SlotStatus::BOOKED); // Now fully booked!

    // Blocked slot cannot be booked
    $blockedSlot = Slot::create(
        tenantId: $tenantId,
        facilityId: $facilityId,
        resourceId: (string) Str::uuid(),
        slotDate: '2026-11-01',
        startTime: '2026-11-01 11:00:00',
        endTime: '2026-11-01 11:30:00',
    );
    $blockedSlot->block();
    expect($blockedSlot->status)->toBe(SlotStatus::BLOCKED);
    expect(fn () => $blockedSlot->book())->toThrow(SlotNotAvailableException::class);
});
