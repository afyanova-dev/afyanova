<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Department;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Room;
use App\Domains\Organization\Domain\Model\Unit;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;

function makeFacilityStructureAdmin(): array
{
    $fixture = makeTenantWithUser([
        'admin.workspace.access',
        'organization.facilities.read',
        'organization.structure.manage',
    ]);
    $fixture['user']->forceFill([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_confirmed_at' => now(),
    ])->save();
    $otherFacility = TenantContextScope::run(
        $fixture['tenant']->id,
        null,
        fn (): Facility => Facility::factory()
            ->forTenant($fixture['tenant']->id, $fixture['organization']->id)
            ->create(),
    );

    return [...$fixture, 'other_facility' => $otherFacility];
}

it('creates an active department and audits the actor and facility', function (): void {
    $fixture = makeFacilityStructureAdmin();

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->post(route('admin.workspace.organization.departments.store'), [
            'facility_id' => $fixture['facility']->id,
            'name' => 'Outpatient',
            'code' => 'OUTPATIENT',
            'kind' => 'CLINICAL',
        ])
        ->assertRedirect(route('admin.workspace.organization', ['facility_id' => $fixture['facility']->id]))
        ->assertSessionHas('status', 'Department Outpatient added.');

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        $department = DB::table('org_departments')
            ->where('facility_id', $fixture['facility']->id)
            ->where('code', 'OUTPATIENT')
            ->first();
        if ($department === null) {
            throw new RuntimeException('Expected the department creation request to persist its department.');
        }

        expect($department->status)->toBe('ACTIVE')
            ->and($department->kind)->toBe('CLINICAL');

        $audit = DB::table('shared_audit_log')
            ->where('action', 'organization.structure.department_created')
            ->where('target_id', $department->id)
            ->first();
        if ($audit === null) {
            throw new RuntimeException('Expected the department creation audit event to be persisted.');
        }

        expect($audit->actor_id)->toBe((string) $fixture['user']->getKey())
            ->and($audit->facility_id)->toBe($fixture['facility']->id);
    });

    it('updates structure records and blocks archiving parents with active children', function (): void {
        $fixture = makeFacilityStructureAdmin();
        [$department, $unit, $room] = TenantContextScope::run(
            $fixture['tenant']->id,
            $fixture['facility']->id,
            function () use ($fixture): array {
                $department = Department::query()->create([
                    'facility_id' => $fixture['facility']->id, 'name' => 'Outpatient', 'code' => 'OUTPATIENT',
                ]);
                $unit = Unit::query()->create([
                    'facility_id' => $fixture['facility']->id, 'department_id' => $department->id,
                    'name' => 'Triage', 'code' => 'TRIAGE',
                ]);
                $room = Room::query()->create([
                    'facility_id' => $fixture['facility']->id, 'unit_id' => $unit->id,
                    'name' => 'Room 1', 'code' => 'ROOM_1',
                ]);

                return [$department, $unit, $room];
            },
        );

        $this->actingAs($fixture['user'])->withSession(['active_facility_id' => $fixture['facility']->id])
            ->put(route('admin.workspace.organization.departments.update', $department->id), [
                'facility_id' => $fixture['facility']->id,
                'name' => 'Ambulatory Care',
                'code' => 'OUTPATIENT',
                'kind' => 'CLINICAL',
            ])->assertRedirect();

        $this->actingAs($fixture['user'])->withSession(['active_facility_id' => $fixture['facility']->id])
            ->post(route('admin.workspace.organization.departments.status', $department->id), [
                'facility_id' => $fixture['facility']->id, 'status' => 'INACTIVE',
            ])->assertUnprocessable();

        $this->actingAs($fixture['user'])->withSession(['active_facility_id' => $fixture['facility']->id])
            ->post(route('admin.workspace.organization.rooms.status', $room->id), [
                'facility_id' => $fixture['facility']->id, 'status' => 'INACTIVE',
            ])->assertRedirect();
        $this->actingAs($fixture['user'])->withSession(['active_facility_id' => $fixture['facility']->id])
            ->post(route('admin.workspace.organization.units.status', $unit->id), [
                'facility_id' => $fixture['facility']->id, 'status' => 'INACTIVE',
            ])->assertRedirect();
        $this->actingAs($fixture['user'])->withSession(['active_facility_id' => $fixture['facility']->id])
            ->post(route('admin.workspace.organization.departments.status', $department->id), [
                'facility_id' => $fixture['facility']->id, 'status' => 'INACTIVE',
            ])->assertRedirect();
        $this->actingAs($fixture['user'])->withSession(['active_facility_id' => $fixture['facility']->id])
            ->post(route('admin.workspace.organization.units.status', $unit->id), [
                'facility_id' => $fixture['facility']->id, 'status' => 'ACTIVE',
            ])->assertUnprocessable();
        foreach ([[$department, 'departments'], [$unit, 'units'], [$room, 'rooms']] as [$record, $kind]) {
            $this->actingAs($fixture['user'])->withSession(['active_facility_id' => $fixture['facility']->id])
                ->post(route("admin.workspace.organization.{$kind}.status", $record->id), [
                    'facility_id' => $fixture['facility']->id, 'status' => 'ACTIVE',
                ])->assertRedirect();
        }

        TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($department, $unit, $room): void {
            expect(DB::table('org_departments')->where('id', $department->id)->value('name'))->toBe('Ambulatory Care')
                ->and(DB::table('org_departments')->where('id', $department->id)->value('status'))->toBe('ACTIVE')
                ->and(DB::table('org_units')->where('id', $unit->id)->value('status'))->toBe('ACTIVE')
                ->and(DB::table('org_rooms')->where('id', $room->id)->value('status'))->toBe('ACTIVE')
                ->and(DB::table('shared_audit_log')->where('target_id', $department->id)
                    ->where('action', 'organization.structure.department_updated')->exists())->toBeTrue();
        });
    });
});

it('denies a facility structure manager from creating departments at another facility', function (): void {
    $fixture = makeFacilityStructureAdmin();

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->get(route('admin.workspace.organization'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Organization/Index')
            ->where('selectedFacility', null));

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->post(route('admin.workspace.organization.departments.store'), [
            'facility_id' => $fixture['other_facility']->id,
            'name' => 'Out of scope',
            'code' => 'OUT_OF_SCOPE',
        ])
        ->assertForbidden();

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        expect(DB::table('org_departments')
            ->where('facility_id', $fixture['other_facility']->id)
            ->count())->toBe(0);
    });
});

it('creates a unit under an active department and audits the actor and facility', function (): void {
    $fixture = makeFacilityStructureAdmin();
    $department = TenantContextScope::run(
        $fixture['tenant']->id,
        $fixture['facility']->id,
        fn (): Department => Department::query()->create([
            'facility_id' => $fixture['facility']->id,
            'name' => 'Outpatient',
            'code' => 'OUTPATIENT',
        ]),
    );

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->post(route('admin.workspace.organization.units.store'), [
            'facility_id' => $fixture['facility']->id,
            'department_id' => $department->id,
            'name' => 'Triage',
            'code' => 'TRIAGE',
        ])
        ->assertRedirect(route('admin.workspace.organization', ['facility_id' => $fixture['facility']->id]))
        ->assertSessionHas('status', 'Unit Triage added.');

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $department): void {
        $unit = DB::table('org_units')
            ->where('facility_id', $fixture['facility']->id)
            ->where('code', 'TRIAGE')
            ->first();
        if ($unit === null) {
            throw new RuntimeException('Expected the unit creation request to persist its unit.');
        }

        expect($unit->department_id)->toBe($department->id)
            ->and($unit->unit_type)->toBe('GENERIC')
            ->and($unit->status)->toBe('ACTIVE');

        $audit = DB::table('shared_audit_log')
            ->where('action', 'organization.structure.unit_created')
            ->where('target_id', $unit->id)
            ->first();
        if ($audit === null) {
            throw new RuntimeException('Expected the unit creation audit event to be persisted.');
        }

        expect($audit->actor_id)->toBe((string) $fixture['user']->getKey())
            ->and($audit->facility_id)->toBe($fixture['facility']->id);
    });
});

it('rejects a department that belongs to a different facility when creating a unit', function (): void {
    $fixture = makeFacilityStructureAdmin();
    $otherDepartment = TenantContextScope::run(
        $fixture['tenant']->id,
        $fixture['other_facility']->id,
        fn (): Department => Department::query()->create([
            'facility_id' => $fixture['other_facility']->id,
            'name' => 'Other Outpatient',
            'code' => 'OTHER_OUTPATIENT',
        ]),
    );

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->post(route('admin.workspace.organization.units.store'), [
            'facility_id' => $fixture['facility']->id,
            'department_id' => $otherDepartment->id,
            'name' => 'Invalid Unit',
            'code' => 'INVALID_UNIT',
        ])
        ->assertStatus(422);

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        expect(DB::table('org_units')->where('facility_id', $fixture['facility']->id)->count())->toBe(0)
            ->and(DB::table('shared_audit_log')
                ->where('action', 'organization.structure.unit_created')
                ->count())->toBe(0);
    });
});

it('creates a facility-level room and audits the actor and facility', function (): void {
    $fixture = makeFacilityStructureAdmin();

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->post(route('admin.workspace.organization.rooms.store'), [
            'facility_id' => $fixture['facility']->id,
            'name' => 'Consultation Room',
            'code' => 'CONSULTATION_1',
        ])
        ->assertRedirect(route('admin.workspace.organization', ['facility_id' => $fixture['facility']->id]))
        ->assertSessionHas('status', 'Room Consultation Room added.');

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        $room = DB::table('org_rooms')
            ->where('facility_id', $fixture['facility']->id)
            ->where('code', 'CONSULTATION_1')
            ->first();
        if ($room === null) {
            throw new RuntimeException('Expected the room creation request to persist its room.');
        }

        expect($room->unit_id)->toBeNull()
            ->and($room->room_type)->toBe('GENERIC')
            ->and($room->status)->toBe('ACTIVE');

        $audit = DB::table('shared_audit_log')
            ->where('action', 'organization.structure.room_created')
            ->where('target_id', $room->id)
            ->first();
        if ($audit === null) {
            throw new RuntimeException('Expected the room creation audit event to be persisted.');
        }

        expect($audit->actor_id)->toBe((string) $fixture['user']->getKey())
            ->and($audit->facility_id)->toBe($fixture['facility']->id);
    });
});

it('shows a field validation error when a room code is already used at the facility', function (): void {
    $fixture = makeFacilityStructureAdmin();
    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        Room::query()->create([
            'facility_id' => $fixture['facility']->id,
            'name' => 'Existing Room',
            'code' => 'CONSULTATION_1',
        ]);
    });

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->post(route('admin.workspace.organization.rooms.store'), [
            'facility_id' => $fixture['facility']->id,
            'name' => 'Another Room',
            'code' => 'CONSULTATION_1',
        ])
        ->assertSessionHasErrors([
            'code' => 'This code is already in use at this facility.',
        ]);

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        expect(DB::table('org_rooms')->where('facility_id', $fixture['facility']->id)->count())->toBe(1);
    });
});

it('creates a room under an active unit in the same facility', function (): void {
    $fixture = makeFacilityStructureAdmin();
    $unit = TenantContextScope::run(
        $fixture['tenant']->id,
        $fixture['facility']->id,
        function () use ($fixture): Unit {
            $department = Department::query()->create([
                'facility_id' => $fixture['facility']->id,
                'name' => 'Outpatient',
                'code' => 'OUTPATIENT',
            ]);

            return Unit::query()->create([
                'facility_id' => $fixture['facility']->id,
                'department_id' => $department->id,
                'name' => 'Triage',
                'code' => 'TRIAGE',
            ]);
        },
    );

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->post(route('admin.workspace.organization.rooms.store'), [
            'facility_id' => $fixture['facility']->id,
            'unit_id' => $unit->id,
            'name' => 'Triage Room',
            'code' => 'TRIAGE_ROOM',
            'room_type' => 'CONSULTATION',
        ])
        ->assertRedirect(route('admin.workspace.organization', ['facility_id' => $fixture['facility']->id]))
        ->assertSessionHas('status', 'Room Triage Room added.');

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $unit): void {
        $room = DB::table('org_rooms')
            ->where('facility_id', $fixture['facility']->id)
            ->where('code', 'TRIAGE_ROOM')
            ->first();
        if ($room === null) {
            throw new RuntimeException('Expected the room creation request to persist its room.');
        }

        expect($room->unit_id)->toBe($unit->id)
            ->and($room->room_type)->toBe('CONSULTATION');
    });
});

it('rejects a unit from another facility when creating a room', function (): void {
    $fixture = makeFacilityStructureAdmin();
    $otherUnit = TenantContextScope::run(
        $fixture['tenant']->id,
        $fixture['other_facility']->id,
        function () use ($fixture): Unit {
            $department = Department::query()->create([
                'facility_id' => $fixture['other_facility']->id,
                'name' => 'Other Outpatient',
                'code' => 'OTHER_OUTPATIENT',
            ]);

            return Unit::query()->create([
                'facility_id' => $fixture['other_facility']->id,
                'department_id' => $department->id,
                'name' => 'Other Triage',
                'code' => 'OTHER_TRIAGE',
            ]);
        },
    );

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $fixture['facility']->id])
        ->post(route('admin.workspace.organization.rooms.store'), [
            'facility_id' => $fixture['facility']->id,
            'unit_id' => $otherUnit->id,
            'name' => 'Invalid Room',
            'code' => 'INVALID_ROOM',
        ])
        ->assertStatus(422);

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture): void {
        expect(DB::table('org_rooms')->where('facility_id', $fixture['facility']->id)->count())->toBe(0)
            ->and(DB::table('shared_audit_log')
                ->where('action', 'organization.structure.room_created')
                ->count())->toBe(0);
    });
});

it('shows the authorized facility hierarchy in its workspace panel', function (): void {
    $fixture = makeFacilityStructureAdmin();
    $room = TenantContextScope::run(
        $fixture['tenant']->id,
        $fixture['facility']->id,
        function () use ($fixture): Room {
            $department = Department::query()->create([
                'facility_id' => $fixture['facility']->id,
                'name' => 'Outpatient',
                'code' => 'OUTPATIENT',
            ]);
            $unit = Unit::query()->create([
                'facility_id' => $fixture['facility']->id,
                'department_id' => $department->id,
                'name' => 'Triage',
                'code' => 'TRIAGE',
            ]);

            return Room::query()->create([
                'facility_id' => $fixture['facility']->id,
                'unit_id' => $unit->id,
                'name' => 'Triage Room',
                'code' => 'TRIAGE_ROOM',
            ]);
        },
    );

    TenantContextScope::run($fixture['tenant']->id, $fixture['facility']->id, function () use ($fixture, $room): void {
        $this->actingAs($fixture['user'])
            ->withSession(['active_facility_id' => $fixture['facility']->id])
            ->get(route('admin.workspace.organization', ['facility_id' => $fixture['facility']->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Organization/Index')
                ->where('selectedFacility.facility.id', $fixture['facility']->id)
                ->where('selectedFacility.canManageStructure', true)
                ->where('selectedFacility.departments.0.code', 'OUTPATIENT')
                ->where('selectedFacility.units.0.code', 'TRIAGE')
                ->where('selectedFacility.rooms.0.id', $room->id));

        $this->actingAs($fixture['user'])
            ->withSession(['active_facility_id' => $fixture['facility']->id])
            ->get(route('admin.workspace.organization', ['facility_id' => $fixture['other_facility']->id]))
            ->assertForbidden();
    });
});
