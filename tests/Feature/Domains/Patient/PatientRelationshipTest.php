<?php

declare(strict_types=1);

use App\Domains\Patient\Application\Command\RecordPatientRelationship;
use App\Domains\Patient\Application\Command\RecordPatientRelationshipResult;
use App\Domains\Patient\Application\Command\RemovePatientRelationship;
use App\Domains\Patient\Contracts\Event\PatientRelationshipRecorded;
use App\Domains\Patient\Contracts\Event\PatientRelationshipRemoved;
use App\Domains\Patient\Domain\Enum\RelationshipRole;
use App\Domains\Patient\Domain\Exception\RelationshipAlreadyInactive;
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
 | PHASE 5 (increment A.4) gate — RecordPatientRelationship /
 | RemovePatientRelationship end-to-end through the real CommandBus pipeline
 | (10-patient-domain.md §35-§38; ADR-126). One role-typed
 | pat_patient_relationships aggregate; deactivation, not hard delete.
 */

uses(RefreshDatabase::class);

/**
 * @return array{tenant: mixed, facility: mixed, user: mixed, patientId: string}
 */
function relationshipFixture(array $permissions = ['patient.patients.update']): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser($permissions);
    test()->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
    );

    return ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId];
}

function dispatchRecordRelationship(string $tenantId, string $facilityId, string $patientId, string $actorId, RelationshipRole $role = RelationshipRole::EmergencyContact): RecordPatientRelationshipResult
{
    /** @var RecordPatientRelationshipResult */
    return TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => app(CommandBus::class)->dispatch(new RecordPatientRelationship(
            relationshipId: (string) Str::uuid7(),
            patientId: $patientId,
            actorId: $actorId,
            role: $role,
            relatedName: 'Neema Mkwawa',
            relationshipLabel: 'sister',
            relatedPhone: '+255700111222',
        )),
    );
}

test('RecordPatientRelationship: stores an ACTIVE row, event on outbox, audit (name/phone never in the audit)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = relationshipFixture();

    $result = dispatchRecordRelationship((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id, RelationshipRole::NextOfKin);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_relationships')->where('id', $result->relationshipId)->first());
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('ACTIVE')
        ->and($row->role)->toBe('NEXT_OF_KIN')
        ->and($row->related_name)->toBe('Neema Mkwawa')
        ->and((int) $row->version)->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientRelationshipRecorded::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('patient.PatientRelationshipRecorded');

    $payload = json_decode((string) $outbox->payload, true);
    expect($payload)->not->toContain('Neema Mkwawa')
        ->and($payload)->not->toContain('+255700111222');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.relationship_recorded')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->target_id)->toBe($patientId)
        ->and($audit->metadata)->not->toContain('Neema Mkwawa')
        ->and($audit->metadata)->not->toContain('+255700111222');
});

test('RecordPatientRelationship is rejected for a missing / cross-tenant patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = relationshipFixture();

    expect(fn () => dispatchRecordRelationship((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_relationships')->count()))->toBe(0);
});

test('RecordPatientRelationship is denied without patient.patients.update (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = relationshipFixture([]);

    expect(fn () => dispatchRecordRelationship((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('a patient may hold several relationships of the same role (no invented cardinality — ADR-126 D-8)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = relationshipFixture();

    dispatchRecordRelationship((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id, RelationshipRole::EmergencyContact);
    dispatchRecordRelationship((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id, RelationshipRole::EmergencyContact);

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_relationships')
        ->where('patient_id', $patientId)->where('role', 'EMERGENCY_CONTACT')->count());
    expect($count)->toBe(2);
});

test('RemovePatientRelationship: deactivates the row, bumps version, records PatientRelationshipRemoved + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = relationshipFixture();
    $recorded = dispatchRecordRelationship((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id);

    TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new RemovePatientRelationship($recorded->relationshipId, (string) $user->id, 'contact moved away')),
    );

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_relationships')->where('id', $recorded->relationshipId)->first());
    expect($row->status)->toBe('INACTIVE')
        ->and($row->removed_by_user_id)->toBe((string) $user->id)
        ->and($row->removed_at)->not->toBeNull()
        ->and((int) $row->version)->toBe(1); // row preserved, not deleted

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientRelationshipRemoved::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('patient.PatientRelationshipRemoved');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.relationship_removed')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->target_type)->toBe('patient_relationship')
        ->and($audit->reason)->toBe('contact moved away');
});

test('removing an already-inactive relationship is rejected and writes nothing further', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = relationshipFixture();
    $recorded = dispatchRecordRelationship((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id);

    $remove = fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new RemovePatientRelationship($recorded->relationshipId, (string) $user->id)),
    );

    $remove();
    expect($remove)->toThrow(RelationshipAlreadyInactive::class);

    $auditCount = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.relationship_removed')->count());
    expect($auditCount)->toBe(1);
});

test('RemovePatientRelationship for a missing / cross-tenant relationship is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = relationshipFixture();

    expect(fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new RemovePatientRelationship((string) Str::uuid7(), (string) $user->id)),
    ))->toThrow(NotFound::class);
});

test('RecordPatientRelationship rejects a blank related name and malformed ids (IC §3)', function () {
    expect(fn () => new RecordPatientRelationship((string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), RelationshipRole::Parent, '  '))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new RecordPatientRelationship('nope', (string) Str::uuid7(), (string) Str::uuid7(), RelationshipRole::Parent, 'A Name'))
        ->toThrow(InvalidArgumentException::class);
});
