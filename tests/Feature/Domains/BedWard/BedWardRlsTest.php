<?php

declare(strict_types=1);

use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Domains\BedWard\Domain\Model\Room;
use App\Domains\BedWard\Domain\Model\Ward;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('application layer: BedWard entities created under tenant A are invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $facilityA = (string) Str::uuid7();

    $wardId = TenantContextScope::run($tenantA, null, fn () => (string) Ward::factory()->forTenant($tenantA)->atFacility($facilityA)->create()->id);
    $roomId = TenantContextScope::run($tenantA, null, fn () => (string) Room::factory()->forTenant($tenantA)->atFacility($facilityA)->inWard($wardId)->create()->id);
    $bedId = TenantContextScope::run($tenantA, null, fn () => (string) Bed::factory()->forTenant($tenantA)->atFacility($facilityA)->inWard($wardId)->create()->id);
    $occId = TenantContextScope::run($tenantA, null, fn () => (string) BedOccupancy::factory()->forTenant($tenantA)->atFacility($facilityA)->forBed($bedId)->create()->id);
    $reservationId = TenantContextScope::run($tenantA, null, fn () => (string) BedReservation::factory()->forTenant($tenantA)->atFacility($facilityA)->forBed($bedId)->create()->id);

    // Visible under tenant A
    expect(TenantContextScope::run($tenantA, null, fn () => Ward::query()->find($wardId)))->not->toBeNull()
        ->and(TenantContextScope::run($tenantA, null, fn () => Room::query()->find($roomId)))->not->toBeNull()
        ->and(TenantContextScope::run($tenantA, null, fn () => Bed::query()->find($bedId)))->not->toBeNull()
        ->and(TenantContextScope::run($tenantA, null, fn () => BedOccupancy::query()->find($occId)))->not->toBeNull()
        ->and(TenantContextScope::run($tenantA, null, fn () => BedReservation::query()->find($reservationId)))->not->toBeNull();

    // Invisible under tenant B
    expect(TenantContextScope::run($tenantB, null, fn () => Ward::query()->find($wardId)))->toBeNull()
        ->and(TenantContextScope::run($tenantB, null, fn () => Room::query()->find($roomId)))->toBeNull()
        ->and(TenantContextScope::run($tenantB, null, fn () => Bed::query()->find($bedId)))->toBeNull()
        ->and(TenantContextScope::run($tenantB, null, fn () => BedOccupancy::query()->find($occId)))->toBeNull()
        ->and(TenantContextScope::run($tenantB, null, fn () => BedReservation::query()->find($reservationId)))->toBeNull();
});

it('FORCEs RLS on bw_wards (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'code' => 'WARD-RLS-'.Str::random(5),
        'name' => 'RLS Ward',
        'ward_type' => 'GENERAL',
        'capacity' => 10,
        'status' => 'ACTIVE',
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('bw_wards')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('bw_wards', $tenantA, $rowId, $tenantB, $row);
});

it('FORCEs RLS on bw_rooms (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'ward_id' => (string) Str::uuid7(),
        'name' => 'RLS Room',
        'room_type' => 'STANDARD',
        'isolation_capable' => false,
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('bw_rooms')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('bw_rooms', $tenantA, $rowId, $tenantB, $row);
});

it('FORCEs RLS on bw_beds (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'ward_id' => (string) Str::uuid7(),
        'label' => 'B-RLS-'.Str::random(5),
        'bed_class' => 'STANDARD',
        'features' => json_encode([]),
        'status' => 'AVAILABLE',
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('bw_beds')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('bw_beds', $tenantA, $rowId, $tenantB, $row);
});

it('FORCEs RLS on bw_bed_occupancies (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'bed_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'assigned_by_user_id' => (string) Str::uuid7(),
        'status' => 'ACTIVE',
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('bw_bed_occupancies')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('bw_bed_occupancies', $tenantA, $rowId, $tenantB, $row);
});

it('FORCEs RLS on bw_bed_reservations (database layer)', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'facility_id' => (string) Str::uuid7(),
        'bed_id' => (string) Str::uuid7(),
        'encounter_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'requested_by_user_id' => (string) Str::uuid7(),
        'reason' => 'ADMISSION',
        'hold_until' => now()->addHours(2),
        'status' => 'REQUESTED',
        'occurred_at' => now(),
        'recorded_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('bw_bed_reservations')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('bw_bed_reservations', $tenantA, $rowId, $tenantB, $row);
});
