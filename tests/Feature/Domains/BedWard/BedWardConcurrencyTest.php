<?php

declare(strict_types=1);

use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Domains\BedWard\Domain\Model\Ward;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('database constraint: at most one ACTIVE occupancy per bed (Invariant 2, ADR-147 D-B)', function () {
    ['tenant' => $tenant, 'facility' => $facility] = bedWardFixture();

    $bedId = (string) Str::uuid7();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    // First active occupancy for bedId succeeds
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $bedId): void {
        BedOccupancy::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'bed_id' => $bedId,
            'encounter_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'assigned_by_user_id' => (string) Str::uuid7(),
            'status' => BedOccupancy::STATUS_ACTIVE,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'version' => 0,
        ]);
    });

    // Second active occupancy for the SAME bedId fails at the DB level (partial unique index)
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $bedId): void {
        BedOccupancy::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'bed_id' => $bedId,
            'encounter_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'assigned_by_user_id' => (string) Str::uuid7(),
            'status' => BedOccupancy::STATUS_ACTIVE,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'version' => 0,
        ]);
    }))->toThrow(QueryException::class);
});

test('database constraint: at most one ACTIVE bed occupancy per encounter (Invariant 4, ADR-147 D-B)', function () {
    ['tenant' => $tenant, 'facility' => $facility] = bedWardFixture();

    $encounterId = (string) Str::uuid7();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    // First active occupancy for encounterId succeeds
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $encounterId): void {
        BedOccupancy::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'bed_id' => (string) Str::uuid7(),
            'encounter_id' => $encounterId,
            'patient_id' => (string) Str::uuid7(),
            'assigned_by_user_id' => (string) Str::uuid7(),
            'status' => BedOccupancy::STATUS_ACTIVE,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'version' => 0,
        ]);
    });

    // Second active occupancy for the SAME encounterId fails at the DB level (partial unique index)
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $encounterId): void {
        BedOccupancy::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'bed_id' => (string) Str::uuid7(),
            'encounter_id' => $encounterId,
            'patient_id' => (string) Str::uuid7(),
            'assigned_by_user_id' => (string) Str::uuid7(),
            'status' => BedOccupancy::STATUS_ACTIVE,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'version' => 0,
        ]);
    }))->toThrow(QueryException::class);
});

test('database constraint: at most one open (REQUESTED or HELD) reservation per bed (ADR-149 D-D)', function () {
    ['tenant' => $tenant, 'facility' => $facility] = bedWardFixture();

    $bedId = (string) Str::uuid7();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $bedId): void {
        BedReservation::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'bed_id' => $bedId,
            'requested_by_user_id' => (string) Str::uuid7(),
            'reason' => 'ADMISSION',
            'hold_until' => now()->addHours(2),
            'status' => BedReservation::STATUS_REQUESTED,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'version' => 0,
        ]);
    });

    expect(fn () => TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $bedId): void {
        BedReservation::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'bed_id' => $bedId,
            'requested_by_user_id' => (string) Str::uuid7(),
            'reason' => 'WARD_TRANSFER',
            'hold_until' => now()->addHours(1),
            'status' => BedReservation::STATUS_HELD,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'version' => 0,
        ]);
    }))->toThrow(QueryException::class);
});

test('database constraint: a new reservation is permitted once the prior one is resolved (FULFILLED/CANCELLED/EXPIRED)', function () {
    ['tenant' => $tenant, 'facility' => $facility] = bedWardFixture();

    $bedId = (string) Str::uuid7();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    foreach ([BedReservation::STATUS_FULFILLED, BedReservation::STATUS_CANCELLED, BedReservation::STATUS_EXPIRED] as $resolvedStatus) {
        TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $bedId, $resolvedStatus): void {
            BedReservation::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'bed_id' => $bedId,
                'requested_by_user_id' => (string) Str::uuid7(),
                'reason' => 'ADMISSION',
                'hold_until' => now()->addHours(2),
                'status' => $resolvedStatus,
                'occurred_at' => now(),
                'recorded_at' => now(),
                'version' => 0,
            ]);
        });
    }

    $count = TenantContextScope::run($tenantId, $facilityId, fn () => BedReservation::query()->where('bed_id', $bedId)->count());
    expect($count)->toBe(3);
});

test('database constraint: multiple ENDED occupancies for same bed or encounter are permitted', function () {
    ['tenant' => $tenant, 'facility' => $facility] = bedWardFixture();

    $bedId = (string) Str::uuid7();
    $encounterId = (string) Str::uuid7();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    // Multiple ended occupancies for the same bed & encounter
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $bedId, $encounterId): void {
        BedOccupancy::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'bed_id' => $bedId,
            'encounter_id' => $encounterId,
            'patient_id' => (string) Str::uuid7(),
            'assigned_by_user_id' => (string) Str::uuid7(),
            'status' => BedOccupancy::STATUS_ENDED,
            'end_reason' => 'DISCHARGE',
            'occurred_at' => now()->subDay(),
            'ended_at' => now()->subHours(12),
            'recorded_at' => now()->subDay(),
            'version' => 1,
        ]);

        BedOccupancy::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'bed_id' => $bedId,
            'encounter_id' => $encounterId,
            'patient_id' => (string) Str::uuid7(),
            'assigned_by_user_id' => (string) Str::uuid7(),
            'status' => BedOccupancy::STATUS_ENDED,
            'end_reason' => 'DISCHARGE',
            'occurred_at' => now()->subHours(6),
            'ended_at' => now()->subHours(1),
            'recorded_at' => now()->subHours(6),
            'version' => 1,
        ]);
    });

    $count = TenantContextScope::run($tenantId, $facilityId, fn () => BedOccupancy::query()->where('bed_id', $bedId)->count());
    expect($count)->toBe(2);
});

test('optimistic locking: saving an aggregate with a stale version throws ConcurrencyConflict', function () {
    ['tenant' => $tenant, 'facility' => $facility] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);

    // Simulate two concurrent instances fetched at version 0
    $instance1 = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Ward::query()->find($ward->id));
    $instance2 = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Ward::query()->find($ward->id));

    expect($instance1)->not->toBeNull()
        ->and($instance2)->not->toBeNull();

    // First update succeeds and increments version to 1
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($instance1): void {
        $instance1->name = 'Updated By First';
        $instance1->save();
    });

    // Second update with stale version 0 fails with ConcurrencyConflict
    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($instance2): void {
        $instance2->name = 'Updated By Second';
        $instance2->save();
    }))->toThrow(ConcurrencyConflict::class);
});
