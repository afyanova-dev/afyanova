<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordAllergy;
use App\Domains\Clinical\Contracts\Event\AllergyRecorded;
use App\Domains\Clinical\Domain\Exception\AllergyContextMismatch;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.5) gate — RecordAllergy end-to-end through the real CommandBus
 | (IC §5): authorize -> PatientApi::getPatientSummary ->
 | EncounterApi::getEncounterContext -> patient-state + encounter-state gates ->
 | ACTIVE allergy (SUSPECTED or CONFIRMED) -> versioned save ->
 | AllergyRecorded outbox + clinical.allergies.recorded audit (ADR-132, ADR-135,
 | ADR-141 D-A … D-V).
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/allergy.php';

// ── happy path ─────────────────────────────────────────────────────

test('an active encounter -> an ACTIVE allergy: origin context + substance + times, version 0, AllergyRecorded + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'category' => 'DRUG',
        'substanceName' => 'Penicillin',
        'substanceCode' => '764146007',
        'substanceCodeSystem' => 'SNOMED-CT',
        'reactionDescription' => 'Severe hives and bronchospasm',
        'severity' => 'SEVERE',
        'verificationStatus' => AllergyVerificationStatus::SUSPECTED,
        'authoredContext' => Allergy::AUTHORED_CONTEXT_CLINICIAN,
        'onsetDate' => '2023-04-12',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id) // ORIGINATING facility
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)          // ORIGINATING encounter
        ->and($row->author_user_id)->toBe((string) $user->id)
        ->and($row->authored_context)->toBe('CLINICIAN')
        ->and($row->category)->toBe('DRUG')
        ->and($row->substance_name)->toBe('Penicillin')
        ->and($row->substance_code)->toBe('764146007')
        ->and($row->substance_code_system)->toBe('SNOMED-CT')
        ->and($row->reaction_description)->toBe('Severe hives and bronchospasm')
        ->and($row->severity)->toBe('SEVERE')
        ->and($row->clinical_status)->toBe('ACTIVE')
        ->and($row->verification_status)->toBe('SUSPECTED')
        ->and($row->onset_date)->toBe('2023-04-12')
        ->and($row->resolved_at)->toBeNull()
        ->and((int) $row->version)->toBe(0)
        ->and($row->occurred_at)->not->toBeNull()
        ->and($row->recorded_at)->not->toBeNull();

    // no history row on record
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->count()))->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', AllergyRecorded::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('clinical.AllergyRecorded')
        ->and($outbox->published_at)->toBeNull();

    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'allergyId', 'category', 'clinicalStatus', 'verificationStatus',
        'substanceCodeSystem', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['allergyId'])->toBe($allergyId)
        ->and($payload['category'])->toBe('DRUG')
        ->and($payload['clinicalStatus'])->toBe('ACTIVE')
        ->and($payload['verificationStatus'])->toBe('SUSPECTED')
        ->and($payload['substanceCodeSystem'])->toBe('SNOMED-CT');

    // Deny-listed PHI fields are absent from event payload
    foreach (['Penicillin', '764146007', 'hives', 'bronchospasm', 'SEVERE', '2023-04-12'] as $needle) {
        expect((string) $outbox->payload)->not->toContain($needle);
    }

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.allergies.recorded')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('allergy')
        ->and($audit->target_id)->toBe($allergyId)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('an uncoded allergy (name only) is valid; the event carries a null substanceCodeSystem', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'category' => 'FOOD',
        'substanceName' => 'Shellfish',
        'substanceCode' => null,
        'substanceCodeSystem' => null,
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($row->substance_code)->toBeNull()
        ->and($row->substance_code_system)->toBeNull();

    $payload = json_decode((string) Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', AllergyRecorded::class)->value('payload')), true, 512, JSON_THROW_ON_ERROR);
    expect($payload['substanceCodeSystem'])->toBeNull();
});

test('an allergy can be recorded with NURSING authored_context and CONFIRMED verification_status', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'authoredContext' => Allergy::AUTHORED_CONTEXT_NURSING,
        'verificationStatus' => AllergyVerificationStatus::CONFIRMED,
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($row->authored_context)->toBe('NURSING')
        ->and($row->verification_status)->toBe('CONFIRMED');

    $payload = json_decode((string) Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', AllergyRecorded::class)->value('payload')), true, 512, JSON_THROW_ON_ERROR);
    expect($payload['verificationStatus'])->toBe('CONFIRMED');
});

test('an ARRIVED and a COMPLETED encounter both permit recording an allergy', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create(['disposition' => $status === EncounterStatus::Completed ? 'DISCHARGED' : null])->id);

    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->exists()))->toBeTrue();
})->with([
    'arrived' => [EncounterStatus::Arrived],
    'completed' => [EncounterStatus::Completed],
]);

test('a provisional ACTIVE patient is permitted (ADR-119)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id, ['provisional' => true])->patientId;
    [$_, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    // switch encounter to the provisional patient
    Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->update(['patient_id' => $patientId]));

    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->exists()))->toBeTrue();
});

test('backdating occurred_at > 24h with a reason -> the reason is on the audit row ONLY', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $backdated = CarbonImmutable::now()->subHours(36)->toISOString();
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'occurredAt' => $backdated,
        'reason' => 'recorded from historical paper records',
    ]);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'clinical.allergies.recorded')
        ->where('target_id', $allergyId)->first());

    expect($audit->reason)->toBe('recorded from historical paper records');

    // not on the allergy row
    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect(property_exists($row, 'reason'))->toBeFalse();
});

// ── guards & rejections ───────────────────────────────────────────

test('recording is rejected for a PLANNED / CANCELLED / ABANDONED / LEFT_WITHOUT_BEING_SEEN / TRANSFERRED encounter (409)', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create()->id);

    expect(fn () => clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(EncounterNotEligibleForClinicalRecord::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->count()))->toBe(0);
})->with([
    'PLANNED' => EncounterStatus::Planned,
    'CANCELLED' => EncounterStatus::Cancelled,
    'ABANDONED' => EncounterStatus::Abandoned,
    'LEFT_WITHOUT_BEING_SEEN' => EncounterStatus::LeftWithoutBeingSeen,
    'TRANSFERRED' => EncounterStatus::Transferred,
]);

test('recording is rejected for an INACTIVE / DECEASED / MERGED patient (409)', function (PatientStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, null, fn () => Patient::query()->whereKey($patientId)->update([
        'status' => $status->value,
    ]));

    expect(fn () => clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(PatientNotEligibleForClinicalRecord::class);
})->with([
    'inactive' => [PatientStatus::Inactive],
    'deceased' => [PatientStatus::Deceased],
    'merged' => [PatientStatus::Merged],
]);

test('an unknown patient / unknown encounter -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    // unknown patient
    expect(fn () => clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);

    // unknown encounter
    expect(fn () => clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('a patient / encounter mismatch -> AllergyContextMismatch (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientA, $encounterA] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    [$patientB] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientB, $encounterA, (string) $user->id))
        ->toThrow(AllergyContextMismatch::class);
});

test('an encounter at a facility outside the clinician scope -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = allergyFixture();
    $facilityB = (string) Str::uuid7(); // user holds no role at facilityB

    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facilityA->id, (string) $user->id);
    // Move encounter to facilityB
    Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->update(['facility_id' => $facilityB]));

    expect(fn () => clinicalRecordAllergy((string) $tenant->id, (string) $facilityA->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);
});

test('a caller without clinical.allergies.record is denied (AuthorizationException)', function () {
    // Fixture with only read key
    $fixture = allergyFixture(['clinical.allergies.read']);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $fixture['tenant']->id, (string) $fixture['facility']->id, (string) $fixture['user']->id);

    expect(fn () => clinicalRecordAllergy((string) $fixture['tenant']->id, (string) $fixture['facility']->id, $patientId, $encounterId, (string) $fixture['user']->id))
        ->toThrow(AuthorizationException::class);
});

test('care-team membership is NOT required to record an allergy', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    // verify no care-team assignment
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->exists()))->toBeTrue();
});

test('replaying RecordAllergy with the same idempotencyKey does not create a duplicate', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $key = 'idemp-allergy-'.Str::random(8);
    $allergyId = (string) Str::uuid7();

    $cmd = new RecordAllergy(
        allergyId: $allergyId,
        actorId: (string) $user->id,
        patientId: $patientId,
        encounterId: $encounterId,
        category: 'DRUG',
        substance: AllergySubstance::of('Aspirin'),
        idempotencyKey: $key,
    );

    $id1 = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($cmd)->allergyId);
    $id2 = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($cmd)->allergyId);

    expect($id1)->toBe($allergyId)
        ->and($id2)->toBe($allergyId)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->count()))->toBe(1);
});
