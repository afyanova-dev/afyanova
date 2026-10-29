<?php

declare(strict_types=1);

use App\Domains\Patient\Application\Command\RegisterPatient;
use App\Domains\Patient\Application\Command\RegisterPatientResult;
use App\Domains\Patient\Application\Command\RegisterPatientReview;
use App\Domains\Patient\Contracts\Event\PatientDuplicateDetected;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Exception\PatientRegistrationBlocked;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (scope A) gate — ADR-117 pre-commit duplicate-detection gate inside
 | RegisterPatient: BLOCK -> PatientRegistrationBlocked (409, nothing written);
 | REVIEW -> RegisterPatientReview + PatientDuplicateDetected, no patient, no
 | `patient.registered` audit; ALLOW -> proceeds; override -> proceeds + audit.
 */

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function dispatchRegisterWithDuplicates(string $tenantId, string $facilityId, array $overrides): RegisterPatientResult|RegisterPatientReview
{
    $command = new RegisterPatient(
        patientId: $overrides['patientId'] ?? (string) Str::uuid7(),
        actorId: $overrides['actorId'],
        givenName: $overrides['givenName'] ?? 'Asha',
        familyName: $overrides['familyName'] ?? 'Mkwawa',
        middleName: null,
        displayName: null,
        sex: $overrides['sex'] ?? Sex::Female,
        dateOfBirth: $overrides['dateOfBirth'] ?? '1990-05-01',
        dobPrecision: DobPrecision::Known,
        primaryPhone: $overrides['primaryPhone'] ?? null,
        email: null,
        provisional: false,
        source: 'RECEPTION',
        idempotencyKey: null,
        nationalId: $overrides['nationalId'] ?? null,
        insuranceMemberNumber: null,
        overrideDuplicate: $overrides['overrideDuplicate'] ?? false,
        overrideReason: $overrides['overrideReason'] ?? null,
    );

    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

function seedExistingPatient(string $tenantId, array $attributes): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create($attributes)->id);
}

test('ALLOW — an unrelated registration proceeds normally', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.create']);
    $this->actingAs($user);

    seedExistingPatient((string) $tenant->id, ['given_name' => 'Juma', 'family_name' => 'Otieno', 'date_of_birth' => '1960-01-01']);

    $result = dispatchRegisterWithDuplicates((string) $tenant->id, (string) $facility->id, ['actorId' => (string) $user->id]);

    expect($result)->toBeInstanceOf(RegisterPatientResult::class);
});

test('BLOCK — an exact identity collision is rejected (409) and nothing is written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.create']);
    $this->actingAs($user);

    seedExistingPatient((string) $tenant->id, [
        'given_name' => 'Asha', 'family_name' => 'Mkwawa', 'date_of_birth' => '1990-05-01', 'sex' => Sex::Female,
    ]);

    expect(fn () => dispatchRegisterWithDuplicates((string) $tenant->id, (string) $facility->id, ['actorId' => (string) $user->id]))
        ->toThrow(PatientRegistrationBlocked::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->count()))->toBe(1) // only the seed
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.registered')->count()))->toBe(0);
});

test('BLOCK — an exact VERIFIED national-identifier collision is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.create']);
    $this->actingAs($user);

    $existingId = seedExistingPatient((string) $tenant->id, ['given_name' => 'Someone', 'family_name' => 'Else', 'date_of_birth' => '1970-02-02']);
    TenantContextScope::run((string) $tenant->id, null, fn () => PatientIdentifier::factory()
        ->forTenant((string) $tenant->id)->forPatient($existingId)->withValue('19900501-01234')->verified()->create());

    expect(fn () => dispatchRegisterWithDuplicates((string) $tenant->id, (string) $facility->id, [
        'actorId' => (string) $user->id,
        'givenName' => 'Nowhere', 'familyName' => 'Near', 'dateOfBirth' => '2001-09-09',
        'nationalId' => '19900501 01234', // same normalized value
    ]))->toThrow(PatientRegistrationBlocked::class);
});

test('REVIEW — a meaningful potential match returns candidates, creates no patient, emits PatientDuplicateDetected, writes no registration audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.create']);
    $this->actingAs($user);

    $existingId = seedExistingPatient((string) $tenant->id, [
        'patient_number' => 'PAT-00000001',
        'given_name' => 'Asha', 'family_name' => 'Mkwawa', 'display_name' => 'Asha Mkwawa',
        'date_of_birth' => '1990-05-01', 'sex' => Sex::Unknown,
    ]);

    $result = dispatchRegisterWithDuplicates((string) $tenant->id, (string) $facility->id, [
        'actorId' => (string) $user->id,
        'sex' => Sex::Unknown, // name + dob align, sex unknown -> 75 -> REVIEW
    ]);

    expect($result)->toBeInstanceOf(RegisterPatientReview::class)
        ->and($result->candidates)->toHaveCount(1)
        ->and($result->candidates[0]->patientId)->toBe($existingId)
        ->and($result->candidates[0]->patientNumber)->toBe('PAT-00000001')
        ->and($result->candidates[0]->displayName)->toBe('Asha Mkwawa');

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->count()))->toBe(1); // no new patient

    $event = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientDuplicateDetected::class)->first());
    expect($event)->not->toBeNull()
        ->and($event->event_name)->toBe('patient.PatientDuplicateDetected');

    $payload = json_decode((string) $event->payload, true);
    expect($payload['candidatePatientIds'])->toBe([$existingId])
        ->and($payload)->not->toContain('Asha')      // PHI-free
        ->and($payload)->not->toContain('1990-05-01');

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'patient.registered')->count()))->toBe(0);
});

test('override without patient.patients.override_duplicate is denied by the bus', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.create']);
    $this->actingAs($user);

    seedExistingPatient((string) $tenant->id, ['given_name' => 'Asha', 'family_name' => 'Mkwawa', 'date_of_birth' => '1990-05-01', 'sex' => Sex::Female]);

    expect(fn () => dispatchRegisterWithDuplicates((string) $tenant->id, (string) $facility->id, [
        'actorId' => (string) $user->id,
        'overrideDuplicate' => true,
        'overrideReason' => 'same-day twin, confirmed distinct person',
    ]))->toThrow(AuthorizationException::class);
});

test('override with both permissions creates the patient and audits the override', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.create', 'patient.patients.override_duplicate']);
    $this->actingAs($user);

    $existingId = seedExistingPatient((string) $tenant->id, ['given_name' => 'Asha', 'family_name' => 'Mkwawa', 'date_of_birth' => '1990-05-01', 'sex' => Sex::Female]);

    $result = dispatchRegisterWithDuplicates((string) $tenant->id, (string) $facility->id, [
        'actorId' => (string) $user->id,
        'overrideDuplicate' => true,
        'overrideReason' => 'same-day twin, confirmed distinct person',
    ]);

    expect($result)->toBeInstanceOf(RegisterPatientResult::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->count()))->toBe(2);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.registered')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->reason)->toBe('same-day twin, confirmed distinct person');

    $metadata = json_decode((string) $audit->metadata, true);
    expect($metadata['duplicate_override'])->toBeTrue()
        ->and($metadata['overridden_outcome'])->toBe('BLOCK')
        ->and($metadata['matched_patient_ids'])->toBe([$existingId]);
});

test('overrideDuplicate without a reason is rejected at construction (IC §3)', function () {
    expect(fn () => new RegisterPatient(
        patientId: (string) Str::uuid7(),
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
        overrideDuplicate: true,
    ))->toThrow(InvalidArgumentException::class);
});
