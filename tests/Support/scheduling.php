<?php

declare(strict_types=1);

use App\Domains\Identity\Domain\Model\AccessAssignment;
use App\Domains\Identity\Domain\Model\Permission;
use App\Domains\Identity\Domain\Model\Role;
use App\Domains\Scheduling\Application\Command\AddScheduleException;
use App\Domains\Scheduling\Application\Command\BookAppointment;
use App\Domains\Scheduling\Application\Command\DefineAppointmentType;
use App\Domains\Scheduling\Application\Command\DefineSchedule;
use App\Domains\Scheduling\Application\Command\GenerateSlots;
use App\Domains\Scheduling\Application\Command\HoldSlot;
use App\Domains\Scheduling\Application\Command\RegisterSchedulableResource;
use App\Domains\Scheduling\Application\Command\ReleaseSlotHold;
use App\Domains\Scheduling\Domain\ValueObject\ResourceType;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/security.php';
require_once __DIR__.'/hr.php';

if (! function_exists('schedulingFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function schedulingFixture(?array $permissions = null): array
    {
        $permissions ??= [
            'sch.access',
            'sch.appointment_type.manage',
            'sch.resource.manage',
            'sch.schedule.manage',
            'sch.slot.generate',
            'sch.slot.hold',
            'sch.appointment.book',
            'sch.appointment.manage',
            'sch.appointment.record_arrival',
            'sch.waitlist.manage',
            'hr.access',
            'hr.person.manage',
            'hr.staff.read',
            'hr.staff.manage',
            'hr.leave_type.manage',
            'hr.leave.request',
            'hr.leave.approve',
        ];

        /** @var array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('resolveSchedulingFacilityId')) {
    function resolveSchedulingFacilityId(string $tenantId, ?string $facilityId = null): ?string
    {
        if ($facilityId !== null && $facilityId !== '') {
            return $facilityId;
        }

        if (app()->bound(TenantContext::class) && app(TenantContext::class)->hasTenant()) {
            $ctxFacility = app(TenantContext::class)->facilityId();
            if ($ctxFacility !== null && $ctxFacility !== '') {
                return $ctxFacility;
            }
        }

        $row = DB::table('org_facilities')->where('tenant_id', $tenantId)->value('id');

        return $row !== null ? (string) $row : null;
    }
}

if (! function_exists('defineAppointmentTypeHelper')) {
    function defineAppointmentTypeHelper(
        string $tenantId,
        string $code = 'GEN_CONS',
        string $name = 'General Consultation',
        int $durationMinutes = 30,
        int $bufferBeforeMinutes = 0,
        int $bufferAfterMinutes = 0,
        bool $isVirtual = false,
        bool $allowPatientBooking = true,
        bool $isActive = true,
        ?string $facilityId = null,
        ?string $appointmentTypeId = null,
        ?string $scopeFacilityId = null,
    ): string {
        $effectiveScopeFacility = resolveSchedulingFacilityId($tenantId, $scopeFacilityId ?? $facilityId);
        $id = $appointmentTypeId ?? (string) Str::uuid();

        TenantContextScope::run(
            $tenantId,
            $effectiveScopeFacility,
            function () use (
                $code,
                $name,
                $durationMinutes,
                $bufferBeforeMinutes,
                $bufferAfterMinutes,
                $isVirtual,
                $allowPatientBooking,
                $isActive,
                $facilityId,
                $id,
            ): void {
                app(CommandBus::class)->dispatch(new DefineAppointmentType(
                    code: $code,
                    name: $name,
                    durationMinutes: $durationMinutes,
                    bufferBeforeMinutes: $bufferBeforeMinutes,
                    bufferAfterMinutes: $bufferAfterMinutes,
                    isVirtual: $isVirtual,
                    allowPatientBooking: $allowPatientBooking,
                    isActive: $isActive,
                    facilityId: $facilityId,
                    appointmentTypeId: $id,
                ));
            },
        );

        return $id;
    }
}

if (! function_exists('registerSchedulableResourceHelper')) {
    function registerSchedulableResourceHelper(
        string $tenantId,
        string $facilityId,
        string $resourceRef,
        string $name = 'Dr. Sarah Connor',
        ResourceType|string $resourceType = ResourceType::PROVIDER,
        bool $isActive = true,
        ?string $resourceId = null,
    ): string {
        $id = $resourceId ?? (string) Str::uuid();

        TenantContextScope::run(
            $tenantId,
            $facilityId,
            function () use (
                $facilityId,
                $resourceType,
                $resourceRef,
                $name,
                $isActive,
                $id,
            ): void {
                app(CommandBus::class)->dispatch(new RegisterSchedulableResource(
                    facilityId: $facilityId,
                    resourceType: $resourceType,
                    resourceRef: $resourceRef,
                    name: $name,
                    isActive: $isActive,
                    resourceId: $id,
                ));
            },
        );

        return $id;
    }
}

if (! function_exists('defineScheduleHelper')) {
    function defineScheduleHelper(
        string $tenantId,
        string $facilityId,
        string $resourceId,
        int $dayOfWeek = 1, // Monday
        string $startTime = '09:00',
        string $endTime = '12:00',
        string|CarbonImmutable $effectiveFrom = '2026-01-01',
        string|CarbonImmutable|null $effectiveTo = null,
        ?string $appointmentTypeId = null,
        bool $isActive = true,
        ?string $scheduleId = null,
    ): string {
        $id = $scheduleId ?? (string) Str::uuid();

        TenantContextScope::run(
            $tenantId,
            $facilityId,
            function () use (
                $facilityId,
                $resourceId,
                $dayOfWeek,
                $startTime,
                $endTime,
                $effectiveFrom,
                $effectiveTo,
                $appointmentTypeId,
                $isActive,
                $id,
            ): void {
                app(CommandBus::class)->dispatch(new DefineSchedule(
                    facilityId: $facilityId,
                    resourceId: $resourceId,
                    dayOfWeek: $dayOfWeek,
                    startTime: $startTime,
                    endTime: $endTime,
                    effectiveFrom: $effectiveFrom,
                    effectiveTo: $effectiveTo,
                    appointmentTypeId: $appointmentTypeId,
                    isActive: $isActive,
                    scheduleId: $id,
                ));
            },
        );

        return $id;
    }
}

if (! function_exists('addScheduleExceptionHelper')) {
    function addScheduleExceptionHelper(
        string $tenantId,
        string $facilityId,
        string $resourceId,
        string|CarbonImmutable $exceptionDate,
        string $reason = 'Public Holiday',
        bool $isAvailable = false,
        ?string $startTime = null,
        ?string $endTime = null,
        ?string $exceptionId = null,
    ): string {
        $id = $exceptionId ?? (string) Str::uuid();

        TenantContextScope::run(
            $tenantId,
            $facilityId,
            function () use (
                $facilityId,
                $resourceId,
                $exceptionDate,
                $reason,
                $isAvailable,
                $startTime,
                $endTime,
                $id,
            ): void {
                app(CommandBus::class)->dispatch(new AddScheduleException(
                    facilityId: $facilityId,
                    resourceId: $resourceId,
                    exceptionDate: $exceptionDate,
                    reason: $reason,
                    isAvailable: $isAvailable,
                    startTime: $startTime,
                    endTime: $endTime,
                    exceptionId: $id,
                ));
            },
        );

        return $id;
    }
}

if (! function_exists('generateSlotsHelper')) {
    function generateSlotsHelper(
        string $tenantId,
        string $facilityId,
        string $resourceId,
        string|CarbonImmutable $startDate,
        string|CarbonImmutable $endDate,
        ?string $appointmentTypeId = null,
        ?int $slotDurationMinutes = null,
        int $capacity = 1,
    ): void {
        TenantContextScope::run(
            $tenantId,
            $facilityId,
            function () use (
                $facilityId,
                $resourceId,
                $startDate,
                $endDate,
                $appointmentTypeId,
                $slotDurationMinutes,
                $capacity,
            ): void {
                app(CommandBus::class)->dispatch(new GenerateSlots(
                    facilityId: $facilityId,
                    resourceId: $resourceId,
                    startDate: $startDate,
                    endDate: $endDate,
                    appointmentTypeId: $appointmentTypeId,
                    slotDurationMinutes: $slotDurationMinutes,
                    capacity: $capacity,
                ));
            },
        );
    }
}

if (! function_exists('holdSlotHelper')) {
    function holdSlotHelper(
        string $tenantId,
        string $slotId,
        string $heldBy,
        int $holdMinutes = 15,
        ?string $facilityId = null,
    ): void {
        $effectiveFacility = resolveSchedulingFacilityId($tenantId, $facilityId);

        TenantContextScope::run(
            $tenantId,
            $effectiveFacility,
            function () use ($slotId, $heldBy, $holdMinutes): void {
                app(CommandBus::class)->dispatch(new HoldSlot(
                    slotId: $slotId,
                    heldBy: $heldBy,
                    holdMinutes: $holdMinutes,
                ));
            },
        );
    }
}

if (! function_exists('releaseSlotHoldHelper')) {
    function releaseSlotHoldHelper(
        string $tenantId,
        string $slotId,
        string $reason = 'Manual release',
        ?string $facilityId = null,
    ): void {
        $effectiveFacility = resolveSchedulingFacilityId($tenantId, $facilityId);

        TenantContextScope::run(
            $tenantId,
            $effectiveFacility,
            function () use ($slotId, $reason): void {
                app(CommandBus::class)->dispatch(new ReleaseSlotHold(
                    slotId: $slotId,
                    reason: $reason,
                ));
            },
        );
    }
}

if (! function_exists('bookAppointmentHelper')) {
    function bookAppointmentHelper(
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $resourceId,
        string $appointmentTypeId,
        string $scheduledStart,
        string $scheduledEnd,
        ?string $slotId = null,
        ?string $reason = 'Routine consultation',
        ?string $appointmentId = null,
    ): string {
        $id = $appointmentId ?? (string) Str::uuid();

        TenantContextScope::run(
            $tenantId,
            $facilityId,
            function () use (
                $patientId,
                $resourceId,
                $appointmentTypeId,
                $scheduledStart,
                $scheduledEnd,
                $slotId,
                $reason,
                $facilityId,
                $id,
            ): void {
                app(CommandBus::class)->dispatch(new BookAppointment(
                    patientId: $patientId,
                    resourceId: $resourceId,
                    appointmentTypeId: $appointmentTypeId,
                    scheduledStart: $scheduledStart,
                    scheduledEnd: $scheduledEnd,
                    slotId: $slotId,
                    reason: $reason,
                    facilityId: $facilityId,
                    appointmentId: $id,
                ));
            },
        );

        return $id;
    }
}

if (! function_exists('createDoctorResource')) {
    function createDoctorResource(string $tenantId, string $facilityId, string $first = 'Frank', string $last = 'Bowman'): string
    {
        $personId = createPersonHelper($tenantId, $first, $last);
        $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);

        return registerSchedulableResourceHelper($tenantId, $facilityId, $staffId, "Dr. {$first} {$last}");
    }
}

if (! function_exists('limitedTenantUser')) {
    /**
     * A second user in an existing tenant/facility holding exactly the given
     * permission keys. Fixture builders dispatch manage-level commands, so
     * least-privilege users must be minted AFTER shared setup is built.
     *
     * @param  list<string>  $permissions
     */
    function limitedTenantUser(string $tenantId, string $facilityId, array $permissions): User
    {
        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $permissions): User {
            $user = User::factory()->create(['tenant_id' => $tenantId, 'status' => 'ACTIVE']);
            $role = Role::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'key' => 'limited-'.(string) Str::uuid7()],
                ['name' => 'Limited Test', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY'],
            );
            foreach ($permissions as $key) {
                $perm = Permission::query()->firstOrCreate(['key' => $key]);
                $role->permissions()->syncWithoutDetaching([
                    $perm->id => ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId],
                ]);
            }
            AccessAssignment::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'facility_id' => $facilityId,
                'status' => 'ACTIVE',
                'is_primary' => true,
            ]);

            return $user;
        });
    }
}
