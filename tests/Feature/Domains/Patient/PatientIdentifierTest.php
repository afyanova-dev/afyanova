<?php

declare(strict_types=1);

use App\Domains\Patient\Application\Command\AddPatientIdentifier;
use App\Domains\Patient\Application\Command\AddPatientIdentifierResult;
use App\Domains\Patient\Application\Command\VerifyPatientIdentifier;
use App\Domains\Patient\Contracts\Event\PatientIdentifierAdded;
use App\Domains\Patient\Contracts\Event\PatientIdentifierVerified;
use App\Domains\Patient\Domain\Enum\IdentifierType;
use App\Domains\Patient\Domain\Exception\DuplicateVerifiedIdentifier;
use App\Domains\Patient\Domain\Exception\IdentifierNotPendingVerification;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (scope A) gate — AddPatientIdentifier / VerifyPatientIdentifier
 | end-to-end through the real CommandBus pipeline (10-patient-domain.md
 | §39-43; ADR-117). Add always stores UNVERIFIED; verify promotes to VERIFIED
 | and hits the ADR-117 partial unique index.
 */

uses(RefreshDatabase::class);

/**
 * @return array{tenant: mixed, facility: mixed, user: mixed, patientId: string}
 */
function identifierFixture(array $permissions = ['patient.identifiers.manage']): array
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

function dispatchAddIdentifier(string $tenantId, string $facilityId, string $patientId, string $actorId, string $value = 'AB-123456', IdentifierType $type = IdentifierType::NationalId): AddPatientIdentifierResult
{
    /** @var AddPatientIdentifierResult */
    return TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => app(CommandBus::class)->dispatch(new AddPatientIdentifier(
            identifierId: (string) Str::uuid7(),
            patientId: $patientId,
            actorId: $actorId,
            type: $type,
            value: $value,
        )),
    );
}

test('AddPatientIdentifier: stores an UNVERIFIED row, event on outbox, audit row (value never in the audit)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = identifierFixture();

    $result = dispatchAddIdentifier((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id, 'AB-123 456');

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_identifiers')->where('id', $result->identifierId)->first());
    expect($row)->not->toBeNull()
        ->and($row->verification_status)->toBe('UNVERIFIED')
        ->and($row->value)->toBe('AB-123 456')
        ->and($row->normalized_value)->toBe('AB123456')
        ->and((int) $row->version)->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientIdentifierAdded::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('patient.PatientIdentifierAdded');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.identifier_added')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->target_id)->toBe($patientId)
        ->and($audit->metadata)->not->toContain('AB-123 456')
        ->and($audit->metadata)->not->toContain('AB123456');
});

test('AddPatientIdentifier is rejected for a missing / cross-tenant patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = identifierFixture();

    expect(fn () => dispatchAddIdentifier((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_identifiers')->count());
    expect($count)->toBe(0);
});

test('AddPatientIdentifier is denied without patient.identifiers.manage (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = identifierFixture([]);

    expect(fn () => dispatchAddIdentifier((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('VerifyPatientIdentifier: promotes to VERIFIED, bumps version, records PatientIdentifierVerified + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = identifierFixture();
    $added = dispatchAddIdentifier((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id);

    TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new VerifyPatientIdentifier($added->identifierId, (string) $user->id, 'NIDA_API')),
    );

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_identifiers')->where('id', $added->identifierId)->first());
    expect($row->verification_status)->toBe('VERIFIED')
        ->and($row->verified_by_user_id)->toBe((string) $user->id)
        ->and($row->verification_source)->toBe('NIDA_API')
        ->and($row->verified_at)->not->toBeNull()
        ->and((int) $row->version)->toBe(1);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientIdentifierVerified::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('patient.PatientIdentifierVerified');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.identifier_verified')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->target_type)->toBe('patient_identifier')
        ->and($audit->target_id)->toBe($added->identifierId);
});

test('re-verifying an already-VERIFIED identifier is rejected (§42) and writes nothing', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = identifierFixture();
    $added = dispatchAddIdentifier((string) $tenant->id, (string) $facility->id, $patientId, (string) $user->id);

    $verify = fn () => TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => app(CommandBus::class)->dispatch(new VerifyPatientIdentifier($added->identifierId, (string) $user->id)),
    );

    $verify();
    expect($verify)->toThrow(IdentifierNotPendingVerification::class);

    $auditCount = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.identifier_verified')->count());
    expect($auditCount)->toBe(1); // only the first, successful verify
});

test('two patients cannot both hold the same national identifier VERIFIED — ADR-117 partial unique', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = identifierFixture();

    [$patientA, $patientB] = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => [
            (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
            (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
        ],
    );

    $idA = dispatchAddIdentifier((string) $tenant->id, (string) $facility->id, $patientA, (string) $user->id, '19850101-01234')->identifierId;
    $idB = dispatchAddIdentifier((string) $tenant->id, (string) $facility->id, $patientB, (string) $user->id, '19850101 01234')->identifierId; // same normalized value

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new VerifyPatientIdentifier($idA, (string) $user->id)));

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new VerifyPatientIdentifier($idB, (string) $user->id))))
        ->toThrow(DuplicateVerifiedIdentifier::class);

    // patient B's identifier stays UNVERIFIED — the conflict rolled back
    $rowB = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_identifiers')->where('id', $idB)->first());
    expect($rowB->verification_status)->toBe('UNVERIFIED');
});

test('an UNVERIFIED duplicate of a VERIFIED identifier is allowed (§43 — unverified values may be mistyped)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = identifierFixture();

    [$patientA, $patientB] = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => [
            (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
            (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id,
        ],
    );

    $idA = dispatchAddIdentifier((string) $tenant->id, (string) $facility->id, $patientA, (string) $user->id, 'NID-777')->identifierId;
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new VerifyPatientIdentifier($idA, (string) $user->id)));

    // same value, different patient, left UNVERIFIED — must not throw
    $idB = dispatchAddIdentifier((string) $tenant->id, (string) $facility->id, $patientB, (string) $user->id, 'NID-777')->identifierId;

    $rowB = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_identifiers')->where('id', $idB)->first());
    expect($rowB)->not->toBeNull()
        ->and($rowB->verification_status)->toBe('UNVERIFIED');
});

test('both PHASE 5 identifier permission keys are registered, including ADR-122 view_sensitive', function () {
    expect(PermissionRegistry::has('patient.identifiers.manage'))->toBeTrue()
        ->and(PermissionRegistry::has('patient.identifiers.view_sensitive'))->toBeTrue();
});

test('AddPatientIdentifier rejects a blank value and malformed ids (IC §3)', function () {
    expect(fn () => new AddPatientIdentifier((string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), IdentifierType::NationalId, '   '))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new AddPatientIdentifier('nope', (string) Str::uuid7(), (string) Str::uuid7(), IdentifierType::NationalId, 'x'))
        ->toThrow(InvalidArgumentException::class);
});
