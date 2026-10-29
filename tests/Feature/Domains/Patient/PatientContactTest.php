<?php

declare(strict_types=1);

use App\Domains\Patient\Application\Command\UpdatePatientContact;
use App\Domains\Patient\Application\Command\UpdatePatientContactResult;
use App\Domains\Patient\Contracts\Event\PatientContactUpdated;
use App\Domains\Patient\Domain\Enum\ContactType;
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
 | PHASE 5 (increment A.4b) gate — UpdatePatientContact upsert end-to-end
 | through the real CommandBus pipeline (10-patient-domain.md §31-§32; ADR-126).
 | One command covers create / revise / deactivate; pat_patients.primary_phone
 | and email are never touched.
 */

uses(RefreshDatabase::class);

/**
 * @return array{tenant: mixed, facility: mixed, user: mixed, patientId: string}
 */
function contactFixture(array $permissions = ['patient.patients.update']): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser($permissions);
    test()->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create([
            'primary_phone' => '+255700000001',
            'email' => 'core@example.test',
        ])->id,
    );

    return ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId];
}

function dispatchUpdateContact(string $tenantId, string $facilityId, UpdatePatientContact $command): UpdatePatientContactResult
{
    /** @var UpdatePatientContactResult */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('UpdatePatientContact (create branch): stores an ACTIVE row, event on outbox, audit — value/label never in the audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = contactFixture();
    $contactId = (string) Str::uuid7();

    $result = dispatchUpdateContact((string) $tenant->id, (string) $facility->id, new UpdatePatientContact(
        contactId: $contactId,
        patientId: $patientId,
        actorId: (string) $user->id,
        type: ContactType::Email,
        value: 'Alt.Contact@Example.test',
        label: 'work email',
        isPreferred: true,
    ));

    expect($result->wasCreated)->toBeTrue();

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_contacts')->where('id', $contactId)->first());
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('ACTIVE')
        ->and($row->type)->toBe('EMAIL')
        ->and($row->normalized_value)->toBe('alt.contact@example.test')
        ->and((bool) $row->is_preferred)->toBeTrue()
        ->and((int) $row->version)->toBe(0);

    // pat_patients core fields untouched (ADR-126 D-2)
    $patient = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->first());
    expect($patient->primary_phone)->toBe('+255700000001')
        ->and($patient->email)->toBe('core@example.test');

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientContactUpdated::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('patient.PatientContactUpdated');
    $payload = json_decode((string) $outbox->payload, true);
    expect($payload)->not->toContain('Alt.Contact@Example.test')
        ->and($payload)->not->toContain('work email');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.contact_updated')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->target_id)->toBe($patientId)
        ->and($audit->metadata)->not->toContain('Alt.Contact@Example.test')
        ->and($audit->metadata)->not->toContain('work email');
});

test('UpdatePatientContact (update branch): revises the same contactId in place, bumps version', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = contactFixture();
    $contactId = (string) Str::uuid7();

    $first = dispatchUpdateContact((string) $tenant->id, (string) $facility->id, new UpdatePatientContact(
        contactId: $contactId, patientId: $patientId, actorId: (string) $user->id,
        type: ContactType::Mobile, value: '+255700111222',
    ));
    $second = dispatchUpdateContact((string) $tenant->id, (string) $facility->id, new UpdatePatientContact(
        contactId: $contactId, patientId: $patientId, actorId: (string) $user->id,
        type: ContactType::Landline, value: '022 213 0000', label: 'home',
    ));

    expect($first->wasCreated)->toBeTrue()
        ->and($second->wasCreated)->toBeFalse();

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_contacts')->count());
    expect($count)->toBe(1);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_contacts')->where('id', $contactId)->first());
    expect($row->type)->toBe('LANDLINE')
        ->and($row->normalized_value)->toBe('0222130000')
        ->and($row->label)->toBe('home')
        ->and((int) $row->version)->toBe(1);
});

test('UpdatePatientContact with isActive=false deactivates the contact (row preserved)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = contactFixture();
    $contactId = (string) Str::uuid7();

    dispatchUpdateContact((string) $tenant->id, (string) $facility->id, new UpdatePatientContact(
        contactId: $contactId, patientId: $patientId, actorId: (string) $user->id,
        type: ContactType::Mobile, value: '+255700111222',
    ));
    dispatchUpdateContact((string) $tenant->id, (string) $facility->id, new UpdatePatientContact(
        contactId: $contactId, patientId: $patientId, actorId: (string) $user->id,
        type: ContactType::Mobile, value: '+255700111222', isActive: false,
    ));

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_contacts')->where('id', $contactId)->first());
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('INACTIVE')
        ->and($row->removed_at)->not->toBeNull()
        ->and((int) $row->version)->toBe(1);
});

test('UpdatePatientContact is rejected for a missing / cross-tenant patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = contactFixture();

    expect(fn () => dispatchUpdateContact((string) $tenant->id, (string) $facility->id, new UpdatePatientContact(
        contactId: (string) Str::uuid7(), patientId: (string) Str::uuid7(), actorId: (string) $user->id,
        type: ContactType::Mobile, value: '+255700111222',
    )))->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_contacts')->count()))->toBe(0);
});

test('UpdatePatientContact rejects a contactId that belongs to a different patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientA] = contactFixture();
    $patientB = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id);
    $contactId = (string) Str::uuid7();

    dispatchUpdateContact((string) $tenant->id, (string) $facility->id, new UpdatePatientContact(
        contactId: $contactId, patientId: $patientA, actorId: (string) $user->id,
        type: ContactType::Mobile, value: '+255700111222',
    ));

    expect(fn () => dispatchUpdateContact((string) $tenant->id, (string) $facility->id, new UpdatePatientContact(
        contactId: $contactId, patientId: $patientB, actorId: (string) $user->id,
        type: ContactType::Mobile, value: '+255700999888',
    )))->toThrow(NotFound::class);
});

test('UpdatePatientContact is denied without patient.patients.update (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = contactFixture([]);

    expect(fn () => dispatchUpdateContact((string) $tenant->id, (string) $facility->id, new UpdatePatientContact(
        contactId: (string) Str::uuid7(), patientId: $patientId, actorId: (string) $user->id,
        type: ContactType::Mobile, value: '+255700111222',
    )))->toThrow(AuthorizationException::class);
});

test('a patient may hold several contacts of the same type (no invented cardinality — ADR-126 D-8)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = contactFixture();

    dispatchUpdateContact((string) $tenant->id, (string) $facility->id, new UpdatePatientContact(
        contactId: (string) Str::uuid7(), patientId: $patientId, actorId: (string) $user->id,
        type: ContactType::Mobile, value: '+255700111222',
    ));
    dispatchUpdateContact((string) $tenant->id, (string) $facility->id, new UpdatePatientContact(
        contactId: (string) Str::uuid7(), patientId: $patientId, actorId: (string) $user->id,
        type: ContactType::Mobile, value: '+255700333444', isPreferred: true,
    ));

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_contacts')
        ->where('patient_id', $patientId)->where('type', 'MOBILE')->count());
    expect($count)->toBe(2);
});

test('UpdatePatientContact rejects a blank value and malformed ids (IC §3)', function () {
    expect(fn () => new UpdatePatientContact((string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), ContactType::Mobile, '   '))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new UpdatePatientContact('nope', (string) Str::uuid7(), (string) Str::uuid7(), ContactType::Mobile, '+255700111222'))
        ->toThrow(InvalidArgumentException::class);
});
