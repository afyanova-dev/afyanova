<?php

declare(strict_types=1);

use App\Domains\Patient\Application\Command\UpdatePatient;
use App\Domains\Patient\Application\Command\UpdatePatientResult;
use App\Domains\Patient\Contracts\Event\PatientDuplicateDetected;
use App\Domains\Patient\Contracts\Event\PatientUpdated;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (increment A.5) gate — UpdatePatient partial patch end-to-end
 | through the real CommandBus pipeline (10-patient-domain.md §26, §60; ADR-127).
 */

uses(RefreshDatabase::class);

/**
 * @return array{tenant: mixed, facility: mixed, user: mixed, patientId: string}
 */
function updateFixture(array $permissions = ['patient.patients.update'], array $patientOverrides = []): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser($permissions);
    test()->actingAs($user);

    $base = [
        'given_name' => 'Asha', 'family_name' => 'Mkwawa', 'middle_name' => 'B',
        'display_name' => 'Asha B Mkwawa', 'sex' => Sex::Female, 'date_of_birth' => '1990-05-01',
        'dob_precision' => DobPrecision::Known,
        'primary_phone' => '+255700000000', 'email' => 'asha@example.test',
    ];

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create(array_merge($base, $patientOverrides))->id,
    );

    return ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId];
}

/**
 * @param  array<string, string|null>  $changes
 */
function dispatchUpdatePatient(string $tenantId, string $facilityId, string $patientId, string $actorId, array $changes, ?string $reason = null): UpdatePatientResult
{
    /** @var UpdatePatientResult */
    return TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => app(CommandBus::class)->dispatch(new UpdatePatient($patientId, $actorId, $changes, $reason)),
    );
}

test('a phone/email-only patch changes only those fields, needs no reason, and audits before/after', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = updateFixture();

    $result = dispatchUpdatePatient((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id, [
        'primary_phone' => '+255711111111',
        'email' => null,
    ]);

    expect($result->changedFields)->toEqualCanonicalizing(['primary_phone', 'email'])
        ->and($result->provisionalCleared)->toBeFalse();

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->first());
    expect($row->primary_phone)->toBe('+255711111111')
        ->and($row->email)->toBeNull()
        ->and($row->family_name)->toBe('Mkwawa')      // untouched
        ->and((int) $row->version)->toBe(1);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.updated')->first());
    $meta = json_decode((string) $audit->metadata, true);
    expect($meta['previous'])->toEqual(['primary_phone' => '+255700000000', 'email' => 'asha@example.test'])
        ->and($meta['new'])->toEqual(['primary_phone' => '+255711111111', 'email' => null])
        ->and($meta['changed_fields'])->toEqualCanonicalizing(['primary_phone', 'email'])
        ->and($meta['provisional_cleared'])->toBeFalse()
        ->and($audit->reason)->toBeNull();
});

test('a name change requires a reason — rejected at construction without one', function () {
    expect(fn () => new UpdatePatient((string) Str::uuid7(), (string) Str::uuid7(), ['family_name' => 'Kimaro']))
        ->toThrow(InvalidArgumentException::class);
});

test('a date-of-birth change requires a reason', function () {
    expect(fn () => new UpdatePatient((string) Str::uuid7(), (string) Str::uuid7(), ['date_of_birth' => '1991-01-01']))
        ->toThrow(InvalidArgumentException::class);
});

test('a sex change requires a reason', function () {
    expect(fn () => new UpdatePatient((string) Str::uuid7(), (string) Str::uuid7(), ['sex' => 'MALE']))
        ->toThrow(InvalidArgumentException::class);
});

test('a sensitive correction with a reason succeeds and the reason is on the audit row', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = updateFixture();

    $result = dispatchUpdatePatient((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id, [
        'family_name' => 'Kimaro',
    ], reason: 'corrected per national ID');

    expect($result->changedFields)->toEqualCanonicalizing(['family_name', 'display_name']);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->first());
    expect($row->family_name)->toBe('Kimaro')
        ->and($row->display_name)->toBe('Asha Kimaro'); // re-derived

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.updated')->first());
    $meta = json_decode((string) $audit->metadata, true);
    expect($audit->reason)->toBe('corrected per national ID')
        ->and($meta['previous']['family_name'])->toBe('Mkwawa')
        ->and($meta['new']['family_name'])->toBe('Kimaro');
});

test('an explicitly supplied display_name is used verbatim through the bus', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = updateFixture();

    dispatchUpdatePatient((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id, [
        'given_name' => 'Aisha',
        'display_name' => 'Dr. Aisha Mkwawa',
    ], reason: 'legal name change');

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->first());
    expect($row->given_name)->toBe('Aisha')
        ->and($row->display_name)->toBe('Dr. Aisha Mkwawa');
});

test('a provisional patient is cleared once the four-field rule is met, and the event + audit reflect it', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = updateFixture(
        patientOverrides: [
            'given_name' => 'Unknown', 'family_name' => 'Patient', 'display_name' => 'Unknown Patient',
            'sex' => Sex::Unknown, 'date_of_birth' => null, 'dob_precision' => DobPrecision::Unknown,
            'is_provisional' => true,
        ],
    );

    $result = dispatchUpdatePatient((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id, [
        'given_name' => 'Neema', 'family_name' => 'Mushi', 'sex' => 'FEMALE',
        'date_of_birth' => '1988-02-02', 'dob_precision' => 'KNOWN',
    ], reason: 'completed registration');

    expect($result->provisionalCleared)->toBeTrue();

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->first());
    expect((bool) $row->is_provisional)->toBeFalse();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientUpdated::class)->first());
    $payload = json_decode((string) $outbox->payload, true);
    expect($outbox->event_name)->toBe('patient.PatientUpdated')
        ->and($payload['provisionalCleared'])->toBeTrue()
        ->and($payload['changedFields'])->toEqualCanonicalizing(['given_name', 'family_name', 'display_name', 'sex', 'date_of_birth', 'dob_precision'])
        ->and($payload)->not->toContain('Neema')      // PHI-free
        ->and($payload)->not->toContain('1988-02-02');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.updated')->first());
    expect(json_decode((string) $audit->metadata, true)['provisional_cleared'])->toBeTrue();
});

test('UpdatePatient is denied without patient.patients.update (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = updateFixture([]);

    expect(fn () => dispatchUpdatePatient((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id, [
        'primary_phone' => '+255711111111',
    ]))->toThrow(AuthorizationException::class);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->first());
    expect($row->primary_phone)->toBe('+255700000000'); // unchanged
});

test('UpdatePatient is rejected for a missing / cross-tenant patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = updateFixture();

    expect(fn () => dispatchUpdatePatient((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id, [
        'primary_phone' => '+255711111111',
    ]))->toThrow(NotFound::class);
});

test('a stale in-memory Patient save after an UpdatePatient throws ConcurrencyConflict (optimistic lock intact)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = updateFixture();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($patientId, $user) {
        $stale = Patient::query()->findOrFail($patientId); // version 0

        // a real UpdatePatient goes through the bus -> version 1
        app(CommandBus::class)->dispatch(new UpdatePatient($patientId, (string) $user->id, ['primary_phone' => '+255799999999']));

        // the stale instance still thinks it is version 0
        $stale->primary_phone = '+255788888888';
        $stale->save();
    });
})->throws(ConcurrencyConflict::class);

test('UpdatePatient runs NO duplicate detection, even with a look-alike patient present and even when clearing provisional', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = updateFixture(
        patientOverrides: [
            'given_name' => 'Unknown', 'family_name' => 'Patient', 'display_name' => 'Unknown Patient',
            'sex' => Sex::Unknown, 'date_of_birth' => null, 'dob_precision' => DobPrecision::Unknown,
            'is_provisional' => true,
        ],
    );

    // an exact look-alike of the values we are about to patch in
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Patient::factory()->forTenant((string) $tenant->id)->create([
        'given_name' => 'Neema', 'family_name' => 'Mushi', 'sex' => Sex::Female, 'date_of_birth' => '1988-02-02',
    ]));

    $result = dispatchUpdatePatient((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id, [
        'given_name' => 'Neema', 'family_name' => 'Mushi', 'sex' => 'FEMALE',
        'date_of_birth' => '1988-02-02', 'dob_precision' => 'KNOWN',
    ], reason: 'completing identity');

    expect($result->provisionalCleared)->toBeTrue();

    $dupEvents = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientDuplicateDetected::class)->count());
    expect($dupEvents)->toBe(0);
});

test('UpdatePatient mutates only pat_patients — no other pat_ table, no status, no patient_number', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = updateFixture();

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->first());

    dispatchUpdatePatient((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id, [
        'family_name' => 'Kimaro', 'primary_phone' => '+255711111111',
    ], reason: 'correction');

    $after = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->first());
    expect($after->status)->toBe($before->status)
        ->and($after->status)->toBe(PatientStatus::Active->value)
        ->and($after->patient_number)->toBe($before->patient_number)
        ->and($after->is_provisional)->toBe($before->is_provisional);

    foreach (['pat_patient_contacts', 'pat_patient_addresses', 'pat_patient_relationships', 'pat_patient_identifiers'] as $table) {
        expect(Tenancy::run((string) $tenant->id, fn () => DB::table($table)->count()))->toBe(0);
    }
});

test('UpdatePatient rejects unknown fields, an empty patch, and malformed ids (IC §3)', function () {
    expect(fn () => new UpdatePatient((string) Str::uuid7(), (string) Str::uuid7(), ['status' => 'INACTIVE']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new UpdatePatient((string) Str::uuid7(), (string) Str::uuid7(), []))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new UpdatePatient('nope', (string) Str::uuid7(), ['primary_phone' => '+255700000000']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new UpdatePatient((string) Str::uuid7(), (string) Str::uuid7(), ['given_name' => null], reason: 'x'))
        ->toThrow(InvalidArgumentException::class); // NOT NULL field
});
