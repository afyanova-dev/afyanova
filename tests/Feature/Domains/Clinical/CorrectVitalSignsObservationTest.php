<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectVitalSignsObservation;
use App\Domains\Clinical\Contracts\Event\VitalSignsObservationCorrected;
use App\Domains\Clinical\Domain\Exception\VitalSignsObservationAlreadySuperseded;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
use App\Domains\Clinical\Domain\VitalSignsObservationRepository;
use App\Domains\Encounter\Application\Command\TransferEncounter;
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
 | PHASE 6 (C.2) gate — CorrectVitalSignsObservation (ADR-138 D7): a NEW
 | superseding observation carries the corrected values + supersedes id + a
 | mandatory reason; the original gets a ONE-TIME supersession stamp; its
 | measurement values stay IMMUTABLE. No void/cancel command.
 */

uses(RefreshDatabase::class);

function seedRecordedObservation(string $tenantId, string $facilityId, string $recorderId, array $state = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) VitalSignsObservation::factory()
        ->forTenant($tenantId)->atFacility($facilityId)->recordedBy($recorderId)
        ->create($state)->id);
}

function dispatchCorrect(string $tenantId, ?string $facilityId, string $supersedesId, string $actorId, array $overrides = []): string
{
    $newId = $overrides['newObservationId'] ?? (string) Str::uuid7();

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new CorrectVitalSignsObservation(
        newObservationId: $newId,
        supersedesObservationId: $supersedesId,
        actorId: $actorId,
        measurements: $overrides['measurements'] ?? VitalSigns::of(systolicBp: 128, diastolicBp: 82, heartRate: 74),
        reason: $overrides['reason'] ?? 'BP mis-transcribed, actual 128/82',
        occurredAt: $overrides['occurredAt'] ?? null,
    )));

    return $newId;
}

// ── happy path ─────────────────────────────────────────────────────

test('correction: a new observation supersedes the original; original values immutable; one-time stamp; VitalSignsObservationCorrected + clinical.vitals.corrected audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $originalId = seedRecordedObservation((string) $tenant->id, (string) $facility->id, (string) $user->id, [
        'systolic_bp' => 180, 'diastolic_bp' => 120, 'heart_rate' => 74, 'temperature' => 37.0,
        'weight' => null, 'height' => null, 'oxygen_saturation' => null, 'respiratory_rate' => null, 'pain_score' => null,
    ]);
    $originalBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $originalId)->first());

    $newId = dispatchCorrect((string) $tenant->id, (string) $facility->id, $originalId, (string) $user->id, [
        'measurements' => VitalSigns::of(systolicBp: 128, diastolicBp: 82, heartRate: 74, temperature: 37.0),
        'reason' => 'systolic mis-keyed as 180, actual 128',
    ]);

    // original: values untouched, one-time supersession stamp, version bumped
    $original = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $originalId)->first());
    expect((int) $original->systolic_bp)->toBe(180)             // IMMUTABLE
        ->and((int) $original->diastolic_bp)->toBe(120)
        ->and($original->superseded_at)->not->toBeNull()
        ->and($original->superseded_by_observation_id)->toBe($newId)
        ->and((int) $original->version)->toBe((int) $originalBefore->version + 1)
        ->and($original->occurred_at)->toBe($originalBefore->occurred_at); // context untouched

    // new (correction) row: corrected values + supersedes link + clinical reason
    $correction = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $newId)->first());
    expect($correction->tenant_id)->toBe($original->tenant_id)
        ->and($correction->facility_id)->toBe($original->facility_id)
        ->and($correction->patient_id)->toBe($original->patient_id)
        ->and($correction->encounter_id)->toBe($original->encounter_id)
        ->and($correction->supersedes_observation_id)->toBe($originalId)
        ->and($correction->reason)->toBe('systolic mis-keyed as 180, actual 128')
        ->and((int) $correction->systolic_bp)->toBe(128)
        ->and((int) $correction->version)->toBe(0)
        ->and($correction->occurred_at)->toBe($originalBefore->occurred_at); // inherited clinical time

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', VitalSignsObservationCorrected::class)->first());
    expect($outbox)->not->toBeNull()->and($outbox->event_name)->toBe('clinical.VitalSignsObservationCorrected');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    // PHI-free (ADR-136 / ADR-138 D7): the payload carries ONLY identifiers + the
    // supersession link + the envelope — no measurement-value field and no
    // correction `reason` field. Asserting the exact key set is the robust check
    // (a raw-string substring scan for a numeric value like "128" collides with
    // hex fragments of the random UUIDs in the payload).
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'vitalSignsObservationId', 'supersedesObservationId', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['vitalSignsObservationId'])->toBe($newId)
        ->and($payload['supersedesObservationId'])->toBe($originalId);
    // the distinctive free-text reason cannot appear as a UUID/hex fragment
    expect((string) $outbox->payload)->not->toContain('mis-keyed');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.vitals.corrected')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->target_id)->toBe($newId)
        ->and($audit->reason)->toBeNull()          // rationale is clinical data on the row, not audit
        ->and(json_decode((string) $audit->metadata, true))->toBe([]); // empty — carries no measurement value / reason (ADR-138 D7)
});

test('a correction can itself be corrected — supersede the current row in the chain', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $v1 = seedRecordedObservation((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $v2 = dispatchCorrect((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, ['reason' => 'first correction']);
    $v3 = dispatchCorrect((string) $tenant->id, (string) $facility->id, $v2, (string) $user->id, ['reason' => 'second correction']);

    $rows = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->pluck('superseded_by_observation_id', 'id'));
    expect($rows[$v1])->toBe($v2)
        ->and($rows[$v2])->toBe($v3)
        ->and($rows[$v3])->toBeNull(); // the current one
});

// ── one-time supersession guard ───────────────────────────────────

test('correcting an already-superseded observation -> VitalSignsObservationAlreadySuperseded (409), nothing persisted', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $v1 = seedRecordedObservation((string) $tenant->id, (string) $facility->id, (string) $user->id);
    dispatchCorrect((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, ['reason' => 'first']);

    try {
        dispatchCorrect((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, ['reason' => 'second attempt on v1']);
        test()->fail('expected VitalSignsObservationAlreadySuperseded');
    } catch (VitalSignsObservationAlreadySuperseded $e) {
        expect($e->httpStatus())->toBe(409);
    }

    // exactly 2 rows (v1 + its one correction), not 3
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->count()))->toBe(2);
});

// ── authorization ──────────────────────────────────────────────────

test('correction uses clinical.vitals.record — a caller with only clinical.vitals.read cannot correct', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.vitals.read',
    ]);
    $v1 = seedRecordedObservation((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => dispatchCorrect((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id))
        ->toThrow(AuthorizationException::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->count()))->toBe(1);
});

test('clinical.vitals.record authorizes correction', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.vitals.record',
    ]);
    $v1 = seedRecordedObservation((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $v2 = dispatchCorrect((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $v2)->exists()))->toBeTrue();
});

test('per-observation facility scope: a caller not assigned at the observation facility is denied', function () {
    ['tenant' => $tenant, 'user' => $user] = clinicalFixture();
    $v1 = seedRecordedObservation((string) $tenant->id, (string) Str::uuid7(), (string) $user->id); // different facility

    expect(fn () => dispatchCorrect((string) $tenant->id, null, $v1, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

// ── isolation / concurrency / no encounter call ───────────────────

test('correction succeeds regardless of the encounter\'s later state and makes NO cross-domain call', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $v1 = clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    // push the encounter terminal
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new TransferEncounter($encounterId, (string) $user->id)));

    $v2 = dispatchCorrect((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, ['reason' => 'after transfer']);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $v2)->exists()))->toBeTrue();
});

test('a missing / cross-tenant original -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    expect(fn () => dispatchCorrect((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('a stale in-memory correction save throws ConcurrencyConflict — no new row', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $v1 = seedRecordedObservation((string) $tenant->id, (string) $facility->id, (string) $user->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($v1, $user) {
            $original = VitalSignsObservation::query()->findOrFail($v1);
            VitalSignsObservation::query()->whereKey($v1)->update(['version' => 9]);
            $correction = VitalSignsObservation::correcting(
                id: (string) Str::uuid7(), original: $original, authorUserId: (string) $user->id,
                authoredContext: VitalSignsObservation::AUTHORED_CONTEXT_CLINICIAN,
                measurements: VitalSigns::of(heartRate: 70), reason: 'x',
                occurredAt: $original->occurred_at, recordedAt: CarbonImmutable::now(),
            );
            $original->markSupersededBy((string) $correction->id);
            app(VitalSignsObservationRepository::class)->saveCorrection($original, $correction);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->count()))->toBe(1);
});
