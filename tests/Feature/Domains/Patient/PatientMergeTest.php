<?php

declare(strict_types=1);

use App\Domains\Patient\Application\Command\AddPatientIdentifier;
use App\Domains\Patient\Application\Command\MergePatients;
use App\Domains\Patient\Application\Command\MergePatientsResult;
use App\Domains\Patient\Application\Command\RecordPatientRelationship;
use App\Domains\Patient\Application\Command\RegisterPatient;
use App\Domains\Patient\Application\Command\RegisterPatientAtFacility;
use App\Domains\Patient\Application\Command\RegisterPatientResult;
use App\Domains\Patient\Application\Command\RemovePatientRelationship;
use App\Domains\Patient\Application\Command\RequestUnmerge;
use App\Domains\Patient\Application\Command\UpdatePatient;
use App\Domains\Patient\Application\Command\UpdatePatientAddress;
use App\Domains\Patient\Application\Command\UpdatePatientContact;
use App\Domains\Patient\Application\Command\VerifyPatientIdentifier;
use App\Domains\Patient\Contracts\Event\PatientMerged;
use App\Domains\Patient\Contracts\Event\PatientUnmerged;
use App\Domains\Patient\Domain\Enum\AddressType;
use App\Domains\Patient\Domain\Enum\ContactType;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\IdentifierType;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Enum\RelationshipRole;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Exception\MergedPatientIsImmutable;
use App\Domains\Patient\Domain\Exception\PatientMergeRejected;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (increment A.6) gate — MergePatients / RequestUnmerge end-to-end
 | through the real CommandBus pipeline (10-patient-domain.md §21-§25, §176;
 | ADR-118, ADR-128). Identity-layer only.
 */

uses(RefreshDatabase::class);

/**
 * @return array{tenant: mixed, facility: mixed, user: mixed}
 */
function mergeFixture(array $permissions = ['patient.patients.merge']): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser($permissions);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'facility' => $facility, 'user' => $user];
}

function seedPatient(string $tenantId, string $facilityId, array $attributes = []): string
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => (string) Patient::factory()
        ->forTenant($tenantId)->create($attributes)->id);
}

function dispatchMerge(string $tenantId, string $facilityId, string $survivorId, string $nonSurvivorId, string $actorId): MergePatientsResult
{
    /** @var MergePatientsResult */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new MergePatients($survivorId, $nonSurvivorId, $actorId, 'confirmed duplicate', 'matching NIDA + DOB + phone'),
    ));
}

test('a successful merge: non-surviving -> MERGED, survivor unchanged, both numbers kept, ACTIVE history row, PHI-free event, audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = mergeFixture();
    $survivor = seedPatient((string) $tenant->id, (string) $facility->id, ['patient_number' => 'PAT-00000001', 'status' => PatientStatus::Active]);
    $nonSurvivor = seedPatient((string) $tenant->id, (string) $facility->id, ['patient_number' => 'PAT-00000002', 'status' => PatientStatus::Inactive]);

    $result = dispatchMerge((string) $tenant->id, (string) $facility->id, $survivor, $nonSurvivor, (string) $user->id);

    $rows = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->whereIn('id', [$survivor, $nonSurvivor])->get()->keyBy('id'));
    expect($rows[$nonSurvivor]->status)->toBe('MERGED')
        ->and($rows[$nonSurvivor]->patient_number)->toBe('PAT-00000002') // preserved
        ->and($rows[$survivor]->status)->toBe('ACTIVE')                  // unchanged
        ->and($rows[$survivor]->patient_number)->toBe('PAT-00000001')
        ->and((int) $rows[$survivor]->version)->toBe(0);                 // survivor not touched

    $history = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_identity_history')->where('merge_id', $result->mergeId)->first());
    expect($history)->not->toBeNull()
        ->and($history->status)->toBe('ACTIVE')
        ->and($history->surviving_patient_id)->toBe($survivor)
        ->and($history->non_surviving_patient_id)->toBe($nonSurvivor)
        ->and($history->non_surviving_patient_number)->toBe('PAT-00000002')
        ->and($history->non_surviving_prior_status)->toBe('INACTIVE')    // exact capture
        ->and($history->reason)->toBe('confirmed duplicate')
        ->and($history->evidence)->toBe('matching NIDA + DOB + phone');

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', PatientMerged::class)->first());
    expect($outbox->event_name)->toBe('patient.PatientMerged');
    $payload = json_decode((string) $outbox->payload, true);
    expect($payload['survivingPatientId'])->toBe($survivor)
        ->and($payload['mergeId'])->toBe($result->mergeId)
        ->and($payload)->not->toContain('PAT-00000002')
        ->and($payload)->not->toContain('confirmed duplicate');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.merged')->first());
    expect($audit->target_id)->toBe($nonSurvivor)
        ->and($audit->reason)->toBe('confirmed duplicate');
    $meta = json_decode((string) $audit->metadata, true);
    expect($meta['evidence'])->toBe('matching NIDA + DOB + phone')
        ->and($meta['non_surviving_prior_status'])->toBe('INACTIVE');
});

test('self-merge is rejected at construction', function () {
    $id = (string) Str::uuid7();
    expect(fn () => new MergePatients($id, $id, (string) Str::uuid7(), 'r', 'e'))
        ->toThrow(PatientMergeRejected::class);
});

test('merge requires a non-blank reason and evidence', function () {
    expect(fn () => new MergePatients((string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), '  ', 'e'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new MergePatients((string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), 'r', '  '))
        ->toThrow(InvalidArgumentException::class);
});

test('merge is rejected when the survivor is DECEASED or MERGED, or the non-survivor is DECEASED or MERGED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = mergeFixture();

    $deceasedSurvivor = seedPatient((string) $tenant->id, (string) $facility->id, ['status' => PatientStatus::Deceased]);
    $ok1 = seedPatient((string) $tenant->id, (string) $facility->id);
    expect(fn () => dispatchMerge((string) $tenant->id, (string) $facility->id, $deceasedSurvivor, $ok1, (string) $user->id))
        ->toThrow(PatientMergeRejected::class);

    $mergedSurvivor = seedPatient((string) $tenant->id, (string) $facility->id, ['status' => PatientStatus::Merged]);
    $ok2 = seedPatient((string) $tenant->id, (string) $facility->id);
    expect(fn () => dispatchMerge((string) $tenant->id, (string) $facility->id, $mergedSurvivor, $ok2, (string) $user->id))
        ->toThrow(PatientMergeRejected::class);

    $okS = seedPatient((string) $tenant->id, (string) $facility->id);
    $deceasedNon = seedPatient((string) $tenant->id, (string) $facility->id, ['status' => PatientStatus::Deceased]);
    expect(fn () => dispatchMerge((string) $tenant->id, (string) $facility->id, $okS, $deceasedNon, (string) $user->id))
        ->toThrow(PatientMergeRejected::class);

    $okS2 = seedPatient((string) $tenant->id, (string) $facility->id);
    $mergedNon = seedPatient((string) $tenant->id, (string) $facility->id, ['status' => PatientStatus::Merged]);
    expect(fn () => dispatchMerge((string) $tenant->id, (string) $facility->id, $okS2, $mergedNon, (string) $user->id))
        ->toThrow(PatientMergeRejected::class);

    // nothing was written
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_identity_history')->count()))->toBe(0);
});

test('merge is denied without patient.patients.merge', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = mergeFixture([]);
    $s = seedPatient((string) $tenant->id, (string) $facility->id);
    $n = seedPatient((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchMerge((string) $tenant->id, (string) $facility->id, $s, $n, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('merge is rejected for a missing / cross-tenant patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = mergeFixture();
    $s = seedPatient((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchMerge((string) $tenant->id, (string) $facility->id, $s, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('RequestUnmerge immediately restores the exact prior status and flips the history row to REVERSED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = mergeFixture();
    $survivor = seedPatient((string) $tenant->id, (string) $facility->id);
    $nonSurvivor = seedPatient((string) $tenant->id, (string) $facility->id, ['status' => PatientStatus::Inactive]);
    $merge = dispatchMerge((string) $tenant->id, (string) $facility->id, $survivor, $nonSurvivor, (string) $user->id);

    $result = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new RequestUnmerge($nonSurvivor, (string) $user->id, 'merge was a mistake'),
    ));

    expect($result->restoredStatus)->toBe(PatientStatus::Inactive)
        ->and($result->mergeId)->toBe($merge->mergeId);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $nonSurvivor)->first());
    expect($row->status)->toBe('INACTIVE'); // exact prior status

    $history = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_identity_history')->where('merge_id', $merge->mergeId)->first());
    expect($history->status)->toBe('REVERSED')
        ->and($history->unmerged_by_user_id)->toBe((string) $user->id)
        ->and($history->unmerged_at)->not->toBeNull()
        ->and((int) $history->version)->toBe(1); // row kept, transitioned

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', PatientUnmerged::class)->first());
    expect($outbox->event_name)->toBe('patient.PatientUnmerged');
    expect(json_decode((string) $outbox->payload, true)['mergeId'])->toBe($merge->mergeId);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.unmerged')->first());
    expect($audit->reason)->toBe('merge was a mistake');
});

test('a second RequestUnmerge on the same patient is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = mergeFixture();
    $survivor = seedPatient((string) $tenant->id, (string) $facility->id);
    $nonSurvivor = seedPatient((string) $tenant->id, (string) $facility->id);
    dispatchMerge((string) $tenant->id, (string) $facility->id, $survivor, $nonSurvivor, (string) $user->id);

    $unmerge = fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new RequestUnmerge($nonSurvivor, (string) $user->id, 'reverting')));

    $unmerge();
    expect($unmerge)->toThrow(PatientMergeRejected::class);
});

test('RequestUnmerge is rejected for a patient with no active merge', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = mergeFixture();
    $patient = seedPatient((string) $tenant->id, (string) $facility->id);

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new RequestUnmerge($patient, (string) $user->id, 'x'))))
        ->toThrow(PatientMergeRejected::class);
});

test('a MERGED patient rejects every non-lifecycle write path (Invariant 14 / ADR-128 M-9, M-11)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.merge', 'patient.patients.update', 'patient.identifiers.manage', 'patient.registrations.create']);
    $this->actingAs($user);

    $survivor = seedPatient((string) $tenant->id, (string) $facility->id);
    $merged = seedPatient((string) $tenant->id, (string) $facility->id);

    // give the merged patient a contact / relationship / identifier BEFORE the merge
    $contactId = (string) Str::uuid7();
    $relId = (string) Str::uuid7();
    $identId = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => (string) PatientIdentifier::factory()
        ->forTenant((string) $tenant->id)->forPatient($merged)->create()->id);
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($merged, $contactId, $relId, $user) {
        app(CommandBus::class)->dispatch(new UpdatePatientContact($contactId, $merged, (string) $user->id, ContactType::Mobile, '+255700111222'));
        app(CommandBus::class)->dispatch(new RecordPatientRelationship($relId, $merged, (string) $user->id, RelationshipRole::EmergencyContact, 'Neema'));
    });

    dispatchMerge((string) $tenant->id, (string) $facility->id, $survivor, $merged, (string) $user->id);

    $run = fn (object $command) => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    expect(fn () => $run(new UpdatePatient($merged, (string) $user->id, ['primary_phone' => '+255799999999'])))->toThrow(MergedPatientIsImmutable::class);
    expect(fn () => $run(new AddPatientIdentifier((string) Str::uuid7(), $merged, (string) $user->id, IdentifierType::NationalId, 'X-1')))->toThrow(MergedPatientIsImmutable::class);
    expect(fn () => $run(new VerifyPatientIdentifier($identId, (string) $user->id)))->toThrow(MergedPatientIsImmutable::class);
    expect(fn () => $run(new RecordPatientRelationship((string) Str::uuid7(), $merged, (string) $user->id, RelationshipRole::NextOfKin, 'Baraka')))->toThrow(MergedPatientIsImmutable::class);
    expect(fn () => $run(new RemovePatientRelationship($relId, (string) $user->id)))->toThrow(MergedPatientIsImmutable::class);
    expect(fn () => $run(new UpdatePatientContact($contactId, $merged, (string) $user->id, ContactType::Mobile, '+255700333444')))->toThrow(MergedPatientIsImmutable::class);
    expect(fn () => $run(new UpdatePatientAddress((string) Str::uuid7(), $merged, (string) $user->id, AddressType::Residential, region: 'Dodoma')))->toThrow(MergedPatientIsImmutable::class);
    expect(fn () => $run(new RegisterPatientAtFacility($merged, (string) $facility->id)))->toThrow(MergedPatientIsImmutable::class);
});

test('a MERGED patient is not returned as a duplicate candidate (demographic + verified identifier — ADR-128 M-10)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] =
        makeTenantWithUser(['patient.patients.merge', 'patient.patients.create', 'patient.identifiers.manage']);
    $this->actingAs($user);

    $survivor = seedPatient((string) $tenant->id, (string) $facility->id, ['given_name' => 'Filler', 'family_name' => 'Person', 'date_of_birth' => '1950-01-01']);
    $dup = seedPatient((string) $tenant->id, (string) $facility->id, [
        'given_name' => 'Asha', 'family_name' => 'Mkwawa', 'date_of_birth' => '1990-05-01', 'sex' => Sex::Female,
    ]);
    // verified national id on the soon-to-be-merged patient
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => PatientIdentifier::factory()
        ->forTenant((string) $tenant->id)->forPatient($dup)->ofType(IdentifierType::NationalId)->withValue('19900501-01234')->verified()->create());

    dispatchMerge((string) $tenant->id, (string) $facility->id, $survivor, $dup, (string) $user->id);

    // now registering an identical person must NOT block/review against the MERGED row
    $result = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new RegisterPatient(
            patientId: (string) Str::uuid7(), actorId: (string) $user->id, givenName: 'Asha', familyName: 'Mkwawa',
            middleName: null, displayName: null, sex: Sex::Female, dateOfBirth: '1990-05-01',
            dobPrecision: DobPrecision::Known, primaryPhone: null, email: null,
            nationalId: '19900501 01234',
        ),
    ));

    expect($result)->toBeInstanceOf(RegisterPatientResult::class);
});

test('a failed merge leaves no partial state (transaction rollback)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = mergeFixture();
    $survivor = seedPatient((string) $tenant->id, (string) $facility->id, ['status' => PatientStatus::Deceased]); // ineligible -> handler throws after loading
    $nonSurvivor = seedPatient((string) $tenant->id, (string) $facility->id);

    expect(fn () => dispatchMerge((string) $tenant->id, (string) $facility->id, $survivor, $nonSurvivor, (string) $user->id))
        ->toThrow(PatientMergeRejected::class);

    $nonSurvivorRow = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $nonSurvivor)->first());
    expect($nonSurvivorRow->status)->toBe('ACTIVE')                     // not mutated
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_identity_history')->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', PatientMerged::class)->count()))->toBe(0);
});

it('FORCEs RLS on pat_patient_identity_history (database layer, ADR-128) and isolates by tenant', function () {
    $raw = DB::connection('pgsql_raw');
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();
    $rowId = (string) Str::uuid7();

    $row = [
        'merge_id' => (string) Str::uuid7(),
        'surviving_patient_id' => (string) Str::uuid7(),
        'non_surviving_patient_id' => (string) Str::uuid7(),
        'non_surviving_patient_number' => 'PAT-00000009',
        'non_surviving_prior_status' => 'ACTIVE',
        'reason' => 'r', 'evidence' => 'e',
        'merged_by_user_id' => (string) Str::uuid7(),
        'merged_at' => now(),
        'status' => 'ACTIVE',
    ];

    $raw->transaction(function () use ($raw, $row, $tenantA, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantA]);
        $raw->table('pat_patient_identity_history')->insert([...$row, 'id' => $rowId, 'tenant_id' => $tenantA]);
    });

    assertRlsIsolates('pat_patient_identity_history', $tenantA, $rowId, $tenantB, $row);
});
