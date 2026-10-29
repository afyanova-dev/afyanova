<?php

declare(strict_types=1);

use App\Domains\BedWard\Contracts\Event\BedReservationExpired;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 | PHASE 7 (B.2 hygiene job) — the deferred reservation-expiry sweep (ADR-149
 | D-C). `bw:reservations:expire` iterates tenants, expires lapsed
 | REQUESTED/HELD reservations via the system-only ExpireBedReservation
 | command, reverting a HELD bed to AVAILABLE.
 */

uses(RefreshDatabase::class);

test('it expires a lapsed HELD reservation and reverts the bed to AVAILABLE, leaving fresh and resolved reservations alone', function () {
    ['tenant' => $tenant, 'facility' => $facility] = bedWardFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $ward = createWardFixture($t, $f);

    $lapsedBed = createBedFixture($t, $f, (string) $ward->id, ['status' => Bed::STATUS_RESERVED]);
    $lapsedHeld = createBedReservationFixture($t, $f, (string) $lapsedBed->id, [
        'status' => BedReservation::STATUS_HELD,
        'hold_until' => now()->subHour(),
    ]);

    $freshBed = createBedFixture($t, $f, (string) $ward->id, ['status' => Bed::STATUS_RESERVED]);
    $freshHeld = createBedReservationFixture($t, $f, (string) $freshBed->id, [
        'status' => BedReservation::STATUS_HELD,
        'hold_until' => now()->addHours(3),
    ]);

    $lapsedButCancelled = createBedReservationFixture($t, $f, (string) createBedFixture($t, $f, (string) $ward->id)->id, [
        'status' => BedReservation::STATUS_CANCELLED,
        'hold_until' => now()->subDay(),
    ]);

    $this->artisan('bw:reservations:expire --once')->assertSuccessful();

    [$lapsed, $bedAfter, $fresh, $cancelled] = TenantContextScope::run($t, $f, fn () => [
        BedReservation::query()->find($lapsedHeld->id),
        Bed::query()->find($lapsedBed->id),
        BedReservation::query()->find($freshHeld->id),
        BedReservation::query()->find($lapsedButCancelled->id),
    ]);

    expect($lapsed->status)->toBe(BedReservation::STATUS_EXPIRED)
        ->and($bedAfter->status)->toBe(Bed::STATUS_AVAILABLE)
        ->and($fresh->status)->toBe(BedReservation::STATUS_HELD)
        ->and($cancelled->status)->toBe(BedReservation::STATUS_CANCELLED);

    $event = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', BedReservationExpired::class)->count());
    expect($event)->toBe(1);
});

test('it expires a lapsed REQUESTED reservation (bed never held, so untouched)', function () {
    ['tenant' => $tenant, 'facility' => $facility] = bedWardFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $ward = createWardFixture($t, $f);

    $bed = createBedFixture($t, $f, (string) $ward->id, ['status' => Bed::STATUS_AVAILABLE]);
    $requested = createBedReservationFixture($t, $f, (string) $bed->id, [
        'status' => BedReservation::STATUS_REQUESTED,
        'hold_until' => now()->subMinute(),
    ]);

    $this->artisan('bw:reservations:expire --once')->assertSuccessful();

    [$res, $bedAfter] = TenantContextScope::run($t, $f, fn () => [
        BedReservation::query()->find($requested->id),
        Bed::query()->find($bed->id),
    ]);
    expect($res->status)->toBe(BedReservation::STATUS_EXPIRED)
        ->and($bedAfter->status)->toBe(Bed::STATUS_AVAILABLE);
});

test('it sweeps each tenant independently — tenant A lapsed, tenant B untouched', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA] = bedWardFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB] = bedWardFixture();

    $wardA = createWardFixture((string) $tenantA->id, (string) $facilityA->id);
    $bedA = createBedFixture((string) $tenantA->id, (string) $facilityA->id, (string) $wardA->id, ['status' => Bed::STATUS_RESERVED]);
    $lapsedA = createBedReservationFixture((string) $tenantA->id, (string) $facilityA->id, (string) $bedA->id, [
        'status' => BedReservation::STATUS_HELD,
        'hold_until' => now()->subHour(),
    ]);

    $wardB = createWardFixture((string) $tenantB->id, (string) $facilityB->id);
    $bedB = createBedFixture((string) $tenantB->id, (string) $facilityB->id, (string) $wardB->id, ['status' => Bed::STATUS_RESERVED]);
    $freshB = createBedReservationFixture((string) $tenantB->id, (string) $facilityB->id, (string) $bedB->id, [
        'status' => BedReservation::STATUS_HELD,
        'hold_until' => now()->addHours(2),
    ]);

    $this->artisan('bw:reservations:expire --once')->assertSuccessful();

    $a = TenantContextScope::run((string) $tenantA->id, (string) $facilityA->id, fn () => BedReservation::query()->find($lapsedA->id));
    $b = TenantContextScope::run((string) $tenantB->id, (string) $facilityB->id, fn () => BedReservation::query()->find($freshB->id));

    expect($a->status)->toBe(BedReservation::STATUS_EXPIRED)
        ->and($b->status)->toBe(BedReservation::STATUS_HELD);
});
