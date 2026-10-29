<?php

declare(strict_types=1);

use App\Domains\Patient\Application\Command\MarkPatientDeceased;
use App\Domains\Patient\Application\Command\MarkPatientInactive;
use App\Domains\Patient\Application\Command\ReactivatePatient;
use App\Domains\Patient\Contracts\Event\PatientDeactivated;
use App\Domains\Patient\Contracts\Event\PatientMarkedDeceased;
use App\Domains\Patient\Contracts\Event\PatientReactivated;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Exception\IllegalPatientStatusTransition;
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
 | PHASE 5 (scope A) gate — the three Patient lifecycle commands
 | (MarkPatientInactive / ReactivatePatient / MarkPatientDeceased) end-to-end
 | through the real CommandBus pipeline: authorize (per-subject) -> handle
 | (load -> ADR-116 guard -> versioned save) -> write audit -> write outbox.
 */

uses(RefreshDatabase::class);

test('MarkPatientInactive: ACTIVE patient goes INACTIVE, event on outbox, audit row written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.deactivate']);
    $this->actingAs($user);

    $patientId = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id);

    TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new MarkPatientInactive($patientId, (string) $user->id, 'moved abroad')),
    );

    $patient = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Patient::query()->find($patientId));
    expect($patient->status)->toBe(PatientStatus::Inactive)
        ->and((int) $patient->version)->toBe(1);

    $outboxRow = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientDeactivated::class)->first());
    expect($outboxRow)->not->toBeNull()
        ->and($outboxRow->event_name)->toBe('patient.PatientDeactivated');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'patient.deactivated')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('patient')
        ->and($audit->target_id)->toBe($patientId)
        ->and($audit->reason)->toBe('moved abroad');
});

test('ReactivatePatient: INACTIVE patient goes back to ACTIVE and records PatientReactivated', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.deactivate']);
    $this->actingAs($user);

    $patientId = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->inactive()->create()->id);

    TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new ReactivatePatient($patientId, (string) $user->id)),
    );

    $patient = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Patient::query()->find($patientId));
    expect($patient->status)->toBe(PatientStatus::Active);

    $outboxRow = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientReactivated::class)->first());
    expect($outboxRow)->not->toBeNull();

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'patient.reactivated')->first());
    expect($audit)->not->toBeNull();
});

test('MarkPatientDeceased: ACTIVE patient becomes DECEASED and records PatientMarkedDeceased', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.mark_deceased']);
    $this->actingAs($user);

    $patientId = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id);

    TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new MarkPatientDeceased($patientId, (string) $user->id)),
    );

    $patient = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Patient::query()->find($patientId));
    expect($patient->status)->toBe(PatientStatus::Deceased);

    $outboxRow = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientMarkedDeceased::class)->first());
    expect($outboxRow)->not->toBeNull()
        ->and($outboxRow->event_name)->toBe('patient.PatientMarkedDeceased');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'patient.marked_deceased')->first());
    expect($audit)->not->toBeNull();
});

test('an illegal transition (DECEASED -> INACTIVE) is rejected and nothing is written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.deactivate']);
    $this->actingAs($user);

    $patientId = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->deceased()->create()->id);

    expect(fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new MarkPatientInactive($patientId, (string) $user->id)),
    ))->toThrow(IllegalPatientStatusTransition::class);

    $patient = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Patient::query()->find($patientId));
    expect($patient->status)->toBe(PatientStatus::Deceased)
        ->and((int) $patient->version)->toBe(0); // rolled back

    $outboxCount = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientDeactivated::class)->count());
    expect($outboxCount)->toBe(0);

    $auditCount = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'patient.deactivated')->count());
    expect($auditCount)->toBe(0);
});

test('deactivation is denied without patient.patients.deactivate (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([]);
    $this->actingAs($user);

    $patientId = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id);

    expect(fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new MarkPatientInactive($patientId, (string) $user->id)),
    ))->toThrow(AuthorizationException::class);

    $patient = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Patient::query()->find($patientId));
    expect($patient->status)->toBe(PatientStatus::Active);
});

test('marking deceased is denied to an actor holding only patient.patients.deactivate (distinct key, §45)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.deactivate']);
    $this->actingAs($user);

    $patientId = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id);

    expect(fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new MarkPatientDeceased($patientId, (string) $user->id)),
    ))->toThrow(AuthorizationException::class);
});

test('a lifecycle command for a missing / cross-tenant patient is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.deactivate']);
    $this->actingAs($user);

    expect(fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new MarkPatientInactive((string) Str::uuid7(), (string) $user->id)),
    ))->toThrow(NotFound::class);
});

test('MarkPatientInactive rejects a malformed patientId / actorId (IC §3)', function () {
    expect(fn () => new MarkPatientInactive('not-a-uuid', (string) Str::uuid7()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new MarkPatientInactive((string) Str::uuid7(), 'not-a-uuid'))
        ->toThrow(InvalidArgumentException::class);
});
