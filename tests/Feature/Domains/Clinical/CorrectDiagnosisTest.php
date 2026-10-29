<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectDiagnosis;
use App\Domains\Clinical\Contracts\Event\DiagnosisCorrected;
use App\Domains\Clinical\Domain\DiagnosisRepository;
use App\Domains\Clinical\Domain\Exception\DiagnosisAlreadySuperseded;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
use App\Domains\Encounter\Application\Command\TransferEncounter;
use App\Shared\Authorization\PermissionRegistry;
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
 | PHASE 6 (C.3) gate — CorrectDiagnosis (ADR-139 D4): a NEW superseding
 | diagnosis carries the corrected coding / description / status + supersedes id
 | + a mandatory clinical reason; the original gets a ONE-TIME supersession
 | stamp; its clinical content stays IMMUTABLE. No Update / Resolve / Void.
 */

uses(RefreshDatabase::class);

function seedDiagnosis(string $tenantId, string $facilityId, string $authorId, array $state = []): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Diagnosis::factory()
        ->forTenant($tenantId)->atFacility($facilityId)->authoredBy($authorId)->create($state)->id);
}

function dispatchCorrectDiagnosis(string $tenantId, ?string $facilityId, string $supersedesId, string $actorId, array $overrides = []): string
{
    $newId = $overrides['newDiagnosisId'] ?? (string) Str::uuid7();

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new CorrectDiagnosis(
        newDiagnosisId: $newId,
        supersedesDiagnosisId: $supersedesId,
        actorId: $actorId,
        coding: $overrides['coding'] ?? DiagnosisCode::of(code: 'B50.9', codeSystem: 'ICD-10'),
        description: $overrides['description'] ?? 'Plasmodium falciparum malaria',
        reason: $overrides['reason'] ?? 'species confirmed on blood film',
        clinicalStatus: $overrides['clinicalStatus'] ?? 'CONFIRMED',
        occurredAt: $overrides['occurredAt'] ?? null,
    )));

    return $newId;
}

// ── happy path ─────────────────────────────────────────────────────

test('correction: a new diagnosis supersedes the original; original content immutable; one-time stamp; DiagnosisCorrected + clinical.diagnoses.corrected audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $originalId = seedDiagnosis((string) $tenant->id, (string) $facility->id, (string) $user->id, [
        'code' => 'B54', 'code_system' => 'ICD-10', 'description' => 'Unspecified malaria', 'clinical_status' => 'WORKING',
    ]);
    $originalBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $originalId)->first());

    $newId = dispatchCorrectDiagnosis((string) $tenant->id, (string) $facility->id, $originalId, (string) $user->id, [
        'coding' => DiagnosisCode::of(code: 'B50.9', codeSystem: 'ICD-10'),
        'description' => 'Plasmodium falciparum malaria without complication',
        'clinicalStatus' => 'CONFIRMED',
        'reason' => 'species confirmed; working diagnosis was too broad',
    ]);

    // original: clinical content untouched, one-time supersession stamp, version bumped
    $original = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $originalId)->first());
    expect($original->code)->toBe('B54')                     // IMMUTABLE
        ->and($original->description)->toBe('Unspecified malaria')
        ->and($original->clinical_status)->toBe('WORKING')
        ->and($original->superseded_at)->not->toBeNull()
        ->and($original->superseded_by_diagnosis_id)->toBe($newId)
        ->and((int) $original->version)->toBe((int) $originalBefore->version + 1)
        ->and($original->occurred_at)->toBe($originalBefore->occurred_at);

    // new (correction) row
    $correction = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $newId)->first());
    expect($correction->tenant_id)->toBe($original->tenant_id)
        ->and($correction->facility_id)->toBe($original->facility_id)
        ->and($correction->patient_id)->toBe($original->patient_id)
        ->and($correction->encounter_id)->toBe($original->encounter_id)
        ->and($correction->authored_context)->toBe('CLINICIAN')
        ->and($correction->supersedes_diagnosis_id)->toBe($originalId)
        ->and($correction->code)->toBe('B50.9')
        ->and($correction->description)->toBe('Plasmodium falciparum malaria without complication')
        ->and($correction->clinical_status)->toBe('CONFIRMED')
        ->and($correction->reason)->toBe('species confirmed; working diagnosis was too broad')
        ->and((int) $correction->version)->toBe(0)
        ->and($correction->occurred_at)->toBe($originalBefore->occurred_at); // inherited

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', DiagnosisCorrected::class)->first());
    expect($outbox)->not->toBeNull()->and($outbox->event_name)->toBe('clinical.DiagnosisCorrected');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'diagnosisId', 'supersedesDiagnosisId', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['diagnosisId'])->toBe($newId)
        ->and($payload['supersedesDiagnosisId'])->toBe($originalId);
    foreach (['B50.9', 'B54', 'falciparum', 'species confirmed'] as $needle) {
        expect((string) $outbox->payload)->not->toContain($needle);
    }

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.diagnoses.corrected')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->target_id)->toBe($newId)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
    foreach (['B50.9', 'falciparum', 'species confirmed'] as $needle) {
        expect((string) $audit->metadata)->not->toContain($needle);
    }
});

test('a correction can itself be corrected — supersede the current diagnosis in the chain', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $v1 = seedDiagnosis((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $v2 = dispatchCorrectDiagnosis((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, ['reason' => 'first']);
    $v3 = dispatchCorrectDiagnosis((string) $tenant->id, (string) $facility->id, $v2, (string) $user->id, ['reason' => 'second']);

    $rows = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->pluck('superseded_by_diagnosis_id', 'id'));
    expect($rows[$v1])->toBe($v2)->and($rows[$v2])->toBe($v3)->and($rows[$v3])->toBeNull();
});

// ── one-time supersession guard ───────────────────────────────────

test('correcting an already-superseded diagnosis -> DiagnosisAlreadySuperseded (409), nothing persisted', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $v1 = seedDiagnosis((string) $tenant->id, (string) $facility->id, (string) $user->id);
    dispatchCorrectDiagnosis((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, ['reason' => 'first']);

    try {
        dispatchCorrectDiagnosis((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, ['reason' => 'second attempt on v1']);
        test()->fail('expected DiagnosisAlreadySuperseded');
    } catch (DiagnosisAlreadySuperseded $e) {
        expect($e->httpStatus())->toBe(409);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->count()))->toBe(2);
});

// ── authorization ──────────────────────────────────────────────────

test('correction uses clinical.diagnoses.record — a caller with only clinical.diagnoses.read cannot correct', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.diagnoses.read',
    ]);
    $v1 = seedDiagnosis((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => dispatchCorrectDiagnosis((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id))
        ->toThrow(AuthorizationException::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->count()))->toBe(1);
});

test('clinical.diagnoses.record authorizes correction; there is no clinical.diagnoses.correct key', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.diagnoses.record',
    ]);
    $v1 = seedDiagnosis((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $v2 = dispatchCorrectDiagnosis((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $v2)->exists()))->toBeTrue();

    expect(PermissionRegistry::all())
        ->not->toContain('clinical.diagnoses.correct')
        ->not->toContain('clinical.diagnoses.manage')
        ->not->toContain('clinical.diagnoses.update');
});

test('per-diagnosis facility scope: a caller not assigned at the diagnosis facility is denied', function () {
    ['tenant' => $tenant, 'user' => $user] = clinicalFixture();
    $v1 = seedDiagnosis((string) $tenant->id, (string) Str::uuid7(), (string) $user->id);

    expect(fn () => dispatchCorrectDiagnosis((string) $tenant->id, null, $v1, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

// ── ungated / isolation / concurrency ─────────────────────────────

test('correction succeeds regardless of the encounter\'s later state and makes NO cross-domain call', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $v1 = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new TransferEncounter($encounterId, (string) $user->id)));

    $v2 = dispatchCorrectDiagnosis((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, ['reason' => 'corrected after transfer']);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $v2)->exists()))->toBeTrue();
});

test('a missing / cross-tenant original -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    expect(fn () => dispatchCorrectDiagnosis((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('a stale in-memory correction save throws ConcurrencyConflict — no new row', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $v1 = seedDiagnosis((string) $tenant->id, (string) $facility->id, (string) $user->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($v1, $user) {
            $original = Diagnosis::query()->findOrFail($v1);
            Diagnosis::query()->whereKey($v1)->update(['version' => 9]);
            $correction = Diagnosis::correcting(
                id: (string) Str::uuid7(), original: $original, authorUserId: (string) $user->id,
                coding: DiagnosisCode::none(), description: 'x', clinicalStatus: 'WORKING', reason: 'r',
                occurredAt: $original->occurred_at, recordedAt: CarbonImmutable::now(),
            );
            $original->markSupersededBy((string) $correction->id);
            app(DiagnosisRepository::class)->saveCorrection($original, $correction);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->count()))->toBe(1);
});
