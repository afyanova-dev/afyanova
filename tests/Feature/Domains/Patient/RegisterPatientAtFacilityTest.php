<?php

declare(strict_types=1);

use App\Domains\Patient\Application\Command\RegisterPatientAtFacility;
use App\Domains\Patient\Contracts\Event\PatientRegisteredAtFacility;
use App\Domains\Patient\Domain\Exception\PatientAlreadyRegisteredAtFacility;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 Slice 2 gate — RegisterPatientAtFacility (ADR-114) through the real
 | CommandBus pipeline: tenant-scoped patient existence check, then a
 | versioned PatientRegistration + PatientRegisteredAtFacility on the outbox.
 */

uses(RefreshDatabase::class);

test('it records a patient facility registration and its event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.registrations.create']);
    $this->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
    );

    TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new RegisterPatientAtFacility($patientId, (string) $facility->id)),
    );

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_registrations')
        ->where('patient_id', $patientId)
        ->where('facility_id', $facility->id)
        ->first());
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('ACTIVE');

    $outboxRow = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientRegisteredAtFacility::class)
        ->first());
    expect($outboxRow)->not->toBeNull()
        ->and($outboxRow->event_name)->toBe('patient.PatientRegisteredAtFacility');
});

test('a repeat registration for the same (patient, facility) throws and leaves no duplicate (IC §9.1)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.registrations.create']);
    $this->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
    );

    $dispatch = fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new RegisterPatientAtFacility($patientId, (string) $facility->id)),
    );

    $dispatch();
    expect($dispatch)->toThrow(PatientAlreadyRegisteredAtFacility::class);

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_registrations')
        ->where('patient_id', $patientId)->count());
    expect($count)->toBe(1);
});

test('it rejects a facility registration for a missing or cross-tenant patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.registrations.create']);
    $this->actingAs($user);

    $bogusPatientId = (string) Str::uuid7();

    expect(fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new RegisterPatientAtFacility($bogusPatientId, (string) $facility->id)),
    ))->toThrow(NotFound::class);

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_registrations')->count());
    expect($count)->toBe(0);
});

test('it denies facility registration without patient.registrations.create (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([]); // no permissions
    $this->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
    );

    expect(fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new RegisterPatientAtFacility($patientId, (string) $facility->id)),
    ))->toThrow(AuthorizationException::class);

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_registrations')->count());
    expect($count)->toBe(0);
});
