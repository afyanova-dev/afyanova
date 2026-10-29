<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\VerifyAllergy;
use App\Domains\Clinical\Contracts\Event\AllergyVerified;
use App\Domains\Clinical\Domain\AllergyRepository;
use App\Domains\Clinical\Domain\Exception\IllegalAllergyStatusTransition;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.5) gate — VerifyAllergy (ADR-141 D-K / D-D): transition on the
 | verification axis; CONFIRMED (from SUSPECTED) | REFUTED (atomically drives
 | clinical_status -> INACTIVE); pre-change state in clin_allergy_history;
 | version bumps; AllergyVerified + audit.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/allergy.php';

function dispatchVerifyAllergy(string $tenantId, ?string $facilityId, string $allergyId, string $actorId, string $verificationStatus, ?string $reason = null): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new VerifyAllergy(
        allergyId: $allergyId,
        actorId: $actorId,
        verificationStatus: $verificationStatus,
        reason: $reason,
    )));
}

// ── CONFIRMED path ──────────────────────────────────────────────────

test('VerifyAllergy(CONFIRMED): SUSPECTED -> CONFIRMED, clinical_status unchanged, history row written, version bumps, AllergyVerified + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'verificationStatus' => AllergyVerificationStatus::SUSPECTED,
    ]);

    dispatchVerifyAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, AllergyVerificationStatus::CONFIRMED);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($row->verification_status)->toBe('CONFIRMED')
        ->and($row->clinical_status)->toBe('ACTIVE') // unchanged
        ->and((int) $row->version)->toBe(1);

    $history = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->where('allergy_id', $allergyId)->get());
    expect($history)->toHaveCount(1);
    $h = $history->first();
    expect($h->change_kind)->toBe('VERIFICATION')
        ->and($h->change_type)->toBe('CONFIRMED')
        ->and($h->verification_status)->toBe('SUSPECTED') // pre-change
        ->and($h->clinical_status)->toBe('ACTIVE')
        ->and($h->reason)->toBeNull()
        ->and($h->author_user_id)->toBe((string) $user->id);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', AllergyVerified::class)->first());
    expect($outbox->event_name)->toBe('clinical.AllergyVerified');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'allergyId', 'verificationStatus', 'clinicalStatus', 'occurredAt',
        'correlationId', 'causationId',
    ])
        ->and($payload['verificationStatus'])->toBe('CONFIRMED')
        ->and($payload['clinicalStatus'])->toBe('ACTIVE');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.allergies.verified')->first());
    expect($audit->target_id)->toBe($allergyId)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

// ── REFUTED path ────────────────────────────────────────────────────

test('VerifyAllergy(REFUTED): atomically drives clinical_status -> INACTIVE, stores reason in history ONLY, AllergyVerified + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'verificationStatus' => AllergyVerificationStatus::SUSPECTED,
    ]);

    $refuteReason = 'Skin prick testing and graded oral challenge were completely negative';
    dispatchVerifyAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, AllergyVerificationStatus::REFUTED, $refuteReason);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($row->verification_status)->toBe('REFUTED')
        ->and($row->clinical_status)->toBe('INACTIVE') // atomic biconditional
        ->and((int) $row->version)->toBe(1);

    $h = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->where('allergy_id', $allergyId)->first());
    expect($h->change_kind)->toBe('VERIFICATION')
        ->and($h->change_type)->toBe('REFUTED')
        ->and($h->reason)->toBe($refuteReason)
        ->and($h->verification_status)->toBe('SUSPECTED') // pre-change
        ->and($h->clinical_status)->toBe('ACTIVE'); // pre-change

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.allergies.verified')->first());
    expect($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
    expect((string) $audit->metadata)->not->toContain('Skin prick testing');

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', AllergyVerified::class)->value('payload'));
    $payload = json_decode((string) $outbox, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['verificationStatus'])->toBe('REFUTED')
        ->and($payload['clinicalStatus'])->toBe('INACTIVE');
    expect((string) $outbox)->not->toContain('Skin prick testing');
});

test('VerifyAllergy(REFUTED) from CONFIRMED status succeeds', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'verificationStatus' => AllergyVerificationStatus::CONFIRMED,
    ]);

    dispatchVerifyAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, AllergyVerificationStatus::REFUTED, 'Disproved on re-challenge');

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($row->verification_status)->toBe('REFUTED')
        ->and($row->clinical_status)->toBe('INACTIVE');
});

// ── illegal transitions ───────────────────────────────────────────

test('VerifyAllergy(CONFIRMED) on already-CONFIRMED throws IllegalAllergyStatusTransition (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'verificationStatus' => AllergyVerificationStatus::CONFIRMED,
    ]);

    expect(fn () => dispatchVerifyAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, AllergyVerificationStatus::CONFIRMED))
        ->toThrow(IllegalAllergyStatusTransition::class);
});

test('VerifyAllergy(REFUTED) on already-REFUTED throws IllegalAllergyStatusTransition (409) — terminal', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'verificationStatus' => AllergyVerificationStatus::SUSPECTED,
    ]);

    dispatchVerifyAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, AllergyVerificationStatus::REFUTED, 'First refutation');

    expect(fn () => dispatchVerifyAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, AllergyVerificationStatus::REFUTED, 'Second refutation'))
        ->toThrow(IllegalAllergyStatusTransition::class);
});

// ── concurrency & auth ────────────────────────────────────────────

test('a stale in-memory verify save throws ConcurrencyConflict — no history row written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    $stale = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(AllergyRepository::class)->getById($allergyId));

    dispatchVerifyAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, AllergyVerificationStatus::CONFIRMED);

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($stale, $user): void {
        $now = CarbonImmutable::now();
        $stale->verify(AllergyVerificationStatus::REFUTED, 'Stale refutation', (string) $user->id, $now, $now);
        app(AllergyRepository::class)->save($stale);
    }))->toThrow(ConcurrencyConflict::class);

    // Exactly one history row from the first successful verify
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->where('allergy_id', $allergyId)->count()))->toBe(1);
});

test('VerifyAllergy uses clinical.allergies.record — a caller with only read is denied', function () {
    $fixture = allergyFixture(['clinical.allergies.read']);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $fixture['tenant']->id, (string) $fixture['facility']->id, (string) $fixture['user']->id);

    grantAllergyPermissionsToRole((string) $fixture['tenant']->id, $fixture['role_id'], ['clinical.allergies.record']);
    $allergyId = clinicalRecordAllergy((string) $fixture['tenant']->id, (string) $fixture['facility']->id, $patientId, $encounterId, (string) $fixture['user']->id);

    Tenancy::run((string) $fixture['tenant']->id, fn () => DB::table('idn_permission_role')
        ->where('role_id', $fixture['role_id'])
        ->where('permission_id', DB::table('idn_permissions')->where('key', 'clinical.allergies.record')->value('id'))
        ->delete());

    expect(fn () => dispatchVerifyAllergy((string) $fixture['tenant']->id, (string) $fixture['facility']->id, $allergyId, (string) $fixture['user']->id, AllergyVerificationStatus::CONFIRMED))
        ->toThrow(AuthorizationException::class);
});

test('per-allergy originating-facility scope: a caller not assigned at the allergy facility is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->update(['facility_id' => (string) Str::uuid7()]));

    expect(fn () => dispatchVerifyAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, AllergyVerificationStatus::CONFIRMED))
        ->toThrow(AuthorizationException::class);
});

test('a missing / cross-tenant allergy -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();

    expect(fn () => dispatchVerifyAllergy((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id, AllergyVerificationStatus::CONFIRMED))
        ->toThrow(NotFound::class);
});
