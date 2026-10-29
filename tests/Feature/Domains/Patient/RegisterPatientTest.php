<?php

declare(strict_types=1);

use App\Domains\Patient\Application\Command\RegisterPatient;
use App\Domains\Patient\Application\Command\RegisterPatientResult;
use App\Domains\Patient\Contracts\Event\PatientRegistered;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 Slice 1 gate — RegisterPatient end-to-end through the real
 | CommandBus pipeline (IC §5): authorize -> claim idempotency -> handle ->
 | write audit -> write outbox, one transaction, inside Tenancy::run.
 */

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function dispatchRegisterPatient(string $tenantId, string $facilityId, array $overrides = []): RegisterPatientResult
{
    $command = new RegisterPatient(
        patientId: $overrides['patientId'] ?? (string) Str::uuid7(),
        actorId: $overrides['actorId'],
        givenName: $overrides['givenName'] ?? 'Asha',
        familyName: $overrides['familyName'] ?? 'Mkwawa',
        middleName: $overrides['middleName'] ?? null,
        displayName: $overrides['displayName'] ?? null,
        sex: $overrides['sex'] ?? Sex::Female,
        dateOfBirth: $overrides['dateOfBirth'] ?? '1990-05-01',
        dobPrecision: $overrides['dobPrecision'] ?? DobPrecision::Known,
        primaryPhone: $overrides['primaryPhone'] ?? null,
        email: $overrides['email'] ?? null,
        provisional: $overrides['provisional'] ?? false,
        source: $overrides['source'] ?? 'RECEPTION',
        idempotencyKey: $overrides['idempotencyKey'] ?? null,
    );

    /** @var RegisterPatientResult */
    return TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => app(CommandBus::class)->dispatch($command),
    );
}

test('it registers a patient: aggregate persisted, number allocated, event on the outbox, audit written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.create']);
    $this->actingAs($user);

    $result = dispatchRegisterPatient((string) $tenant->id, (string) $facility->id, ['actorId' => (string) $user->id]);

    expect($result->patientNumber)->toBe('PAT-00000001');

    $patient = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => Patient::query()->find($result->patientId),
    );
    expect($patient)->not->toBeNull()
        ->and($patient->patient_number)->toBe('PAT-00000001')
        ->and($patient->status)->toBe(PatientStatus::Active)
        ->and($patient->is_provisional)->toBeFalse()
        ->and($patient->sex)->toBe(Sex::Female)
        ->and((int) $patient->version)->toBe(0)
        ->and($patient->given_name)->toBe('Asha')
        ->and($patient->registered_by_user_id)->toBe((string) $user->id);
    $registrationExists = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn (): bool => PatientRegistration::query()
            ->where('patient_id', $result->patientId)
            ->where('facility_id', (string) $facility->id)
            ->exists(),
    );
    expect($registrationExists)->toBeTrue();

    $outboxRow = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientRegistered::class)
        ->first());
    expect($outboxRow)->not->toBeNull()
        ->and($outboxRow->event_name)->toBe('patient.PatientRegistered')
        ->and($outboxRow->published_at)->toBeNull();

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'patient.registered')
        ->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('patient')
        ->and($audit->target_id)->toBe($result->patientId);
});

test('it allocates sequential, tenant-scoped patient numbers (ADR-115)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.create']);
    $this->actingAs($user);

    // two distinct people — no duplicate signal overlap, so both ALLOW (ADR-117)
    $first = dispatchRegisterPatient((string) $tenant->id, (string) $facility->id, ['actorId' => (string) $user->id]);
    $second = dispatchRegisterPatient((string) $tenant->id, (string) $facility->id, [
        'actorId' => (string) $user->id,
        'givenName' => 'Juma',
        'familyName' => 'Kikwete',
        'dateOfBirth' => '1978-11-20',
        'sex' => Sex::Male,
    ]);

    expect($first->patientNumber)->toBe('PAT-00000001')
        ->and($second->patientNumber)->toBe('PAT-00000002');

    // a second, independent tenant starts its own sequence at 1
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] =
        makeTenantWithUser(['patient.patients.create']);
    $this->actingAs($userB);

    $thirdInTenantB = dispatchRegisterPatient((string) $tenantB->id, (string) $facilityB->id, ['actorId' => (string) $userB->id]);
    expect($thirdInTenantB->patientNumber)->toBe('PAT-00000001');
});

test('it denies registration to an actor without patient.patients.create (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([]); // no permissions
    $this->actingAs($user);

    expect(fn () => dispatchRegisterPatient((string) $tenant->id, (string) $facility->id, ['actorId' => (string) $user->id]))
        ->toThrow(AuthorizationException::class);

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->count());
    expect($count)->toBe(0); // denied before the handler ever ran
});

test('RegisterPatient rejects a malformed patientId / actorId (IC §3)', function () {
    expect(fn () => new RegisterPatient(
        patientId: 'not-a-uuid',
        actorId: (string) Str::uuid7(),
        givenName: 'Asha',
        familyName: 'Mkwawa',
        middleName: null,
        displayName: null,
        sex: Sex::Female,
        dateOfBirth: null,
        dobPrecision: DobPrecision::Unknown,
        primaryPhone: null,
        email: null,
    ))->toThrow(InvalidArgumentException::class);
});

test('provisional registration is stored with is_provisional = true (ADR-119)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.create']);
    $this->actingAs($user);

    $result = dispatchRegisterPatient((string) $tenant->id, (string) $facility->id, [
        'actorId' => (string) $user->id,
        'givenName' => 'Unknown',
        'familyName' => 'Patient',
        'dateOfBirth' => null,
        'dobPrecision' => DobPrecision::Unknown,
        'sex' => Sex::Unknown,
        'provisional' => true,
        'source' => 'EMERGENCY',
    ]);

    $patient = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => Patient::query()->find($result->patientId),
    );
    expect($patient->is_provisional)->toBeTrue()
        ->and($patient->status)->toBe(PatientStatus::Active); // provisional is a flag, not a status (ADR-119)
});

test('a repeated idempotencyKey does not create a second patient and replays the same result', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.create']);
    $this->actingAs($user);

    $key = (string) Str::uuid7();
    $first = dispatchRegisterPatient((string) $tenant->id, (string) $facility->id, [
        'actorId' => (string) $user->id,
        'idempotencyKey' => $key,
    ]);
    $second = dispatchRegisterPatient((string) $tenant->id, (string) $facility->id, [
        'actorId' => (string) $user->id,
        'idempotencyKey' => $key,
    ]);

    expect($second->patientId)->toBe($first->patientId)
        ->and($second->patientNumber)->toBe($first->patientNumber);

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->count());
    expect($count)->toBe(1);

    $auditCount = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'patient.registered')->count());
    expect($auditCount)->toBe(1); // the replay short-circuits before WriteAuditRow runs again
});
