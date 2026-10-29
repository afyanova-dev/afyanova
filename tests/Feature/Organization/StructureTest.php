<?php

declare(strict_types=1);

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Organization\Domain\Model\Department;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Domains\Organization\Domain\Model\Room;
use App\Domains\Organization\Domain\Model\Unit;
use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

/*
 | PHASE 3 gate — the organisational spine every domain (PHASE 5+) builds on:
 | Tenant → Organization → Facility → Department → Unit → Room
 | (blueprint.md §8; docs/00-foundation/02-organization.md §4–§15, ADR-002/003/007/111).
 */

it('builds Tenant → Org → Facility → Department → Unit → Room and reads the tree back', function (): void {
    $tenant = Tenant::factory()->create();

    [$facilityId, $deptId, $unitId, $roomId] = TenantContextScope::run($tenant->id, null, function () use ($tenant): array {
        $org = Organization::factory()->forTenant($tenant->id)->create();
        $facility = Facility::factory()->forTenant($tenant->id, $org->id)->create();

        $dept = Department::query()->create([
            'facility_id' => $facility->id, 'name' => 'Theatre', 'code' => 'THEATRE',
        ]);
        $unit = Unit::query()->create([
            'facility_id' => $facility->id, 'department_id' => $dept->id,
            'name' => 'Operating Room', 'code' => 'OR', 'unit_type' => 'GENERIC',
        ]);
        $room = Room::query()->create([
            'facility_id' => $facility->id, 'unit_id' => $unit->id,
            'name' => 'OR 1', 'code' => 'OR_1', 'room_type' => 'GENERIC',
        ]);

        return [$facility->id, $dept->id, $unit->id, $room->id];
    });

    $structure = TenantContextScope::run(
        $tenant->id,
        $facilityId,
        fn () => app(OrganizationApi::class)->getFacilityStructure($facilityId),
    );

    expect($structure)->not->toBeNull()
        ->and($structure->facilityId)->toBe($facilityId)
        ->and($structure->departments)->toHaveCount(1)
        ->and($structure->departments[0]['code'])->toBe('THEATRE')
        ->and($structure->departments[0]['units'][0]['code'])->toBe('OR')
        ->and($structure->departments[0]['units'][0]['rooms'][0]['code'])->toBe('OR_1');

    TenantContextScope::run($tenant->id, null, function () use ($facilityId, $deptId, $unitId, $roomId): void {
        $api = app(OrganizationApi::class);
        expect($api->facilityExists($facilityId))->toBeTrue()
            ->and($api->departmentExists($facilityId, $deptId))->toBeTrue()
            ->and($api->unitExists($facilityId, $unitId))->toBeTrue()
            ->and($api->roomExists($facilityId, $roomId))->toBeTrue()
            ->and($api->facilityExists((string) Str::uuid7()))->toBeFalse();
    });
});

it('accepts a small facility with rooms hung straight off a department (Facility → Department → Room)', function (): void {
    // docs 02 §15 — units are optional; a dispensary need not model them.
    $tenant = Tenant::factory()->create();

    $facilityId = TenantContextScope::run($tenant->id, null, function () use ($tenant): string {
        $org = Organization::factory()->forTenant($tenant->id)->create();
        $facility = Facility::factory()->forTenant($tenant->id, $org->id)->ofType('DISPENSARY', 'PRIMARY')->create();

        $dept = Department::query()->create([
            'facility_id' => $facility->id, 'name' => 'Dispensing', 'code' => 'DISPENSING',
        ]);
        Room::query()->create([
            'facility_id' => $facility->id, 'unit_id' => null,
            'name' => 'Counter 1', 'code' => 'COUNTER_1', 'room_type' => 'GENERIC',
        ]);
        expect($dept->id)->toBeUuid();

        return $facility->id;
    });

    $structure = TenantContextScope::run(
        $tenant->id,
        $facilityId,
        fn () => app(OrganizationApi::class)->getFacilityStructure($facilityId),
    );

    expect($structure->departments)->toHaveCount(1)
        ->and($structure->departments[0]['units'])->toBe([]);

    TenantContextScope::run($tenant->id, null, function () use ($facilityId): void {
        expect(Room::query()->where('facility_id', $facilityId)->whereNull('unit_id')->count())->toBe(1);
    });
});

it('does not resolve a structure for a facility in another tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $facilityB = TenantContextScope::run($tenantB->id, null, function () use ($tenantB): string {
        $org = Organization::factory()->forTenant($tenantB->id)->create();

        return Facility::factory()->forTenant($tenantB->id, $org->id)->create()->id;
    });

    $seen = TenantContextScope::run(
        $tenantA->id,
        null,
        fn () => app(OrganizationApi::class)->facilityExists($facilityB),
    );

    expect($seen)->toBeFalse();
});
