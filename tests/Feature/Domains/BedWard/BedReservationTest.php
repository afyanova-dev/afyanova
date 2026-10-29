<?php

declare(strict_types=1);

use App\Domains\BedWard\Application\Command\CancelBedReservation;
use App\Domains\BedWard\Application\Command\HoldBedReservation;
use App\Domains\BedWard\Application\Command\RequestBedReservation;
use App\Domains\BedWard\Contracts\Event\BedReservationCancelled;
use App\Domains\BedWard\Contracts\Event\BedReservationHeld;
use App\Domains\BedWard\Contracts\Event\BedReservationRequested;
use App\Domains\BedWard\Domain\Exception\BedReservationConflict;
use App\Domains\BedWard\Domain\Exception\IllegalBedReservationTransition;
use App\Domains\BedWard\Domain\Exception\IllegalBedTransition;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 7 (B.2) gate — RequestBedReservation / HoldBedReservation /
 | CancelBedReservation (ADR-149 D-B/D-F). Two-stage REQUESTED -> HELD design;
 | a REQUESTED reservation may target a still-OCCUPIED bed.
 */

uses(RefreshDatabase::class);

function dispatchReservationCommand(string $tenantId, string $facilityId, object $command): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('it requests a reservation against a still-OCCUPIED bed: REQUESTED, bed status untouched, event on outbox, audit written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_OCCUPIED,
    ]);
    ['encounterId' => $encounterId] = seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $reservationId = (string) Str::uuid7();
    $holdUntil = CarbonImmutable::now()->addHours(2);

    dispatchReservationCommand((string) $tenant->id, (string) $facility->id, new RequestBedReservation(
        reservationId: $reservationId,
        bedId: (string) $bed->id,
        encounterId: $encounterId,
        patientId: null,
        reason: 'ADMISSION',
        holdUntil: $holdUntil,
        actorId: (string) $user->id,
    ));

    $reservation = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedReservation::query()->find($reservationId));
    expect($reservation)->not->toBeNull()
        ->and($reservation->status)->toBe(BedReservation::STATUS_REQUESTED)
        ->and($reservation->bed_id)->toBe((string) $bed->id)
        ->and($reservation->encounter_id)->toBe($encounterId)
        ->and($reservation->reason)->toBe('ADMISSION');

    $stillOccupied = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($stillOccupied->status)->toBe(Bed::STATUS_OCCUPIED);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', BedReservationRequested::class)
        ->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('bed_ward.BedReservationRequested');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $reservationId)
        ->where('action', 'bed_ward.reservation_requested')
        ->first());
    expect($audit)->not->toBeNull();
});

test('it rejects a second REQUEST against a bed that already has an open reservation', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_AVAILABLE,
    ]);
    createBedReservationFixture((string) $tenant->id, (string) $facility->id, (string) $bed->id, [
        'status' => BedReservation::STATUS_REQUESTED,
    ]);

    expect(fn () => dispatchReservationCommand((string) $tenant->id, (string) $facility->id, new RequestBedReservation(
        reservationId: (string) Str::uuid7(),
        bedId: (string) $bed->id,
        encounterId: null,
        patientId: null,
        reason: 'ADMISSION',
        holdUntil: CarbonImmutable::now()->addHours(1),
        actorId: (string) $user->id,
    )))->toThrow(BedReservationConflict::class);
});

test('it holds a REQUESTED reservation once the bed is AVAILABLE: HELD, bed RESERVED, event on outbox', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_AVAILABLE,
    ]);
    $reservation = createBedReservationFixture((string) $tenant->id, (string) $facility->id, (string) $bed->id, [
        'status' => BedReservation::STATUS_REQUESTED,
        'hold_until' => now()->addHours(2),
    ]);

    dispatchReservationCommand((string) $tenant->id, (string) $facility->id, new HoldBedReservation(
        reservationId: (string) $reservation->id,
        actorId: (string) $user->id,
    ));

    $held = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedReservation::query()->find($reservation->id));
    expect($held->status)->toBe(BedReservation::STATUS_HELD);

    $reservedBed = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($reservedBed->status)->toBe(Bed::STATUS_RESERVED);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', BedReservationHeld::class)
        ->first());
    expect($outbox)->not->toBeNull();
});

test('it rejects HoldBedReservation when the bed is not AVAILABLE', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_OCCUPIED,
    ]);
    $reservation = createBedReservationFixture((string) $tenant->id, (string) $facility->id, (string) $bed->id, [
        'status' => BedReservation::STATUS_REQUESTED,
        'hold_until' => now()->addHours(2),
    ]);

    expect(fn () => dispatchReservationCommand((string) $tenant->id, (string) $facility->id, new HoldBedReservation(
        reservationId: (string) $reservation->id,
        actorId: (string) $user->id,
    )))->toThrow(IllegalBedTransition::class);
});

test('HoldBedReservation rejects a lapsed REQUESTED reservation without persisting the expiry (ADR-149 D-C)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_AVAILABLE,
    ]);
    $reservation = createBedReservationFixture((string) $tenant->id, (string) $facility->id, (string) $bed->id, [
        'status' => BedReservation::STATUS_REQUESTED,
        'hold_until' => now()->subMinute(),
    ]);

    expect(fn () => dispatchReservationCommand((string) $tenant->id, (string) $facility->id, new HoldBedReservation(
        reservationId: (string) $reservation->id,
        actorId: (string) $user->id,
    )))->toThrow(IllegalBedReservationTransition::class);

    // The whole dispatch is one DB transaction that rolls back on the thrown
    // exception, so the reservation is NOT persisted as EXPIRED here — it
    // stays REQUESTED until a SUCCEEDING command touches it (AssignBed's own
    // self-heal, tested separately, or the deferred hygiene job).
    $stillRequested = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedReservation::query()->find($reservation->id));
    expect($stillRequested->status)->toBe(BedReservation::STATUS_REQUESTED);
});

test('it cancels a REQUESTED reservation (bed untouched) and a HELD reservation (bed reverts to AVAILABLE)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();
    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);

    // REQUESTED cancel
    $bedA = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['status' => Bed::STATUS_AVAILABLE]);
    $reqReservation = createBedReservationFixture((string) $tenant->id, (string) $facility->id, (string) $bedA->id, [
        'status' => BedReservation::STATUS_REQUESTED,
    ]);
    dispatchReservationCommand((string) $tenant->id, (string) $facility->id, new CancelBedReservation(
        reservationId: (string) $reqReservation->id,
        actorId: (string) $user->id,
    ));
    $cancelledReq = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedReservation::query()->find($reqReservation->id));
    expect($cancelledReq->status)->toBe(BedReservation::STATUS_CANCELLED);
    $bedAAfter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bedA->id));
    expect($bedAAfter->status)->toBe(Bed::STATUS_AVAILABLE);

    // HELD cancel
    $bedB = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['status' => Bed::STATUS_RESERVED]);
    $heldReservation = createBedReservationFixture((string) $tenant->id, (string) $facility->id, (string) $bedB->id, [
        'status' => BedReservation::STATUS_HELD,
    ]);
    dispatchReservationCommand((string) $tenant->id, (string) $facility->id, new CancelBedReservation(
        reservationId: (string) $heldReservation->id,
        actorId: (string) $user->id,
    ));
    $cancelledHeld = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedReservation::query()->find($heldReservation->id));
    expect($cancelledHeld->status)->toBe(BedReservation::STATUS_CANCELLED);
    $bedBAfter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bedB->id));
    expect($bedBAfter->status)->toBe(Bed::STATUS_AVAILABLE);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', BedReservationCancelled::class)
        ->count());
    expect($outbox)->toBe(2);
});

test('it rejects cancelling an already-terminal reservation', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();
    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id);
    $reservation = createBedReservationFixture((string) $tenant->id, (string) $facility->id, (string) $bed->id, [
        'status' => BedReservation::STATUS_FULFILLED,
    ]);

    expect(fn () => dispatchReservationCommand((string) $tenant->id, (string) $facility->id, new CancelBedReservation(
        reservationId: (string) $reservation->id,
        actorId: (string) $user->id,
    )))->toThrow(IllegalBedReservationTransition::class);
});

test('RequestBedReservation / HoldBedReservation / CancelBedReservation are denied without bed_ward.reservations.manage', function () {
    $permissions = ['bed_ward.access', 'bed_ward.beds.assign'];
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture($permissions);

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['status' => Bed::STATUS_AVAILABLE]);

    expect(fn () => dispatchReservationCommand((string) $tenant->id, (string) $facility->id, new RequestBedReservation(
        reservationId: (string) Str::uuid7(),
        bedId: (string) $bed->id,
        encounterId: null,
        patientId: null,
        reason: 'ADMISSION',
        holdUntil: CarbonImmutable::now()->addHour(),
        actorId: (string) $user->id,
    )))->toThrow(AuthorizationException::class);

    $reservation = createBedReservationFixture((string) $tenant->id, (string) $facility->id, (string) $bed->id, [
        'status' => BedReservation::STATUS_REQUESTED,
    ]);

    expect(fn () => dispatchReservationCommand((string) $tenant->id, (string) $facility->id, new HoldBedReservation(
        reservationId: (string) $reservation->id,
        actorId: (string) $user->id,
    )))->toThrow(AuthorizationException::class);

    expect(fn () => dispatchReservationCommand((string) $tenant->id, (string) $facility->id, new CancelBedReservation(
        reservationId: (string) $reservation->id,
        actorId: (string) $user->id,
    )))->toThrow(AuthorizationException::class);
});

test('RequestBedReservation rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new RequestBedReservation(
        reservationId: (string) Str::uuid7(),
        bedId: 'not-a-uuid',
        encounterId: null,
        patientId: null,
        reason: 'ADMISSION',
        holdUntil: CarbonImmutable::now()->addHour(),
        actorId: (string) Str::uuid7(),
    ))->toThrow(InvalidArgumentException::class);
});

test('RequestBedReservation rejects a blank reason', function () {
    expect(fn () => new RequestBedReservation(
        reservationId: (string) Str::uuid7(),
        bedId: (string) Str::uuid7(),
        encounterId: null,
        patientId: null,
        reason: '   ',
        holdUntil: CarbonImmutable::now()->addHour(),
        actorId: (string) Str::uuid7(),
    ))->toThrow(InvalidArgumentException::class);
});
