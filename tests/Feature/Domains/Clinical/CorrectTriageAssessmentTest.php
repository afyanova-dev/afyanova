<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectTriageAssessment;
use App\Domains\Clinical\Contracts\Event\TriageAssessmentCorrected;
use App\Domains\Clinical\Domain\Exception\TriageAssessmentAlreadySuperseded;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
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
 | PHASE 6 (C.9) gate — CorrectTriageAssessment (re-triage / correction) through
 | the real CommandBus (ADR-145 D-F): a NEW superseding row + mandatory reason,
 | a one-time stamp on the original, NOT encounter-state-gated, same
 | clinical.triage.record key.
 */

uses(RefreshDatabase::class);

/** Seed a RECORDED triage assessment straight through the factory (no .record key needed). */
function seedTriage(string $tenantId, string $facilityId, string $actorId): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) TriageAssessment::factory()
        ->forTenant($tenantId)->atFacility($facilityId)->authoredBy($actorId)->create()->id);
}

function correctTriage(string $tenantId, ?string $facilityId, string $originalId, string $actorId, array $o = []): string
{
    $newId = $o['newTriageAssessmentId'] ?? (string) Str::uuid7();

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new CorrectTriageAssessment(
        newTriageAssessmentId: $newId,
        supersedesTriageAssessmentId: $originalId,
        actorId: $actorId,
        chiefComplaint: $o['chiefComplaint'] ?? 'Chest pain, now radiating',
        triagePriority: $o['triagePriority'] ?? 'EMERGENCY',
        reason: $o['reason'] ?? 'patient deteriorated in the waiting room',
        acuity: $o['acuity'] ?? null,
        consciousness: $o['consciousness'] ?? null,
        sourceVitalsObservationId: $o['sourceVitalsObservationId'] ?? null,
    )));

    return $newId;
}

test('re-triage: the original is stamped superseded, the new row carries supersedes + reason + the new priority, one TriageAssessmentCorrected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $v1 = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, ['triagePriority' => 'NORMAL']);

    $v2 = correctTriage((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, [
        'triagePriority' => 'EMERGENCY', 'reason' => 'chest pain worsened',
    ]);

    $rows = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->orderBy('occurred_at')->get()->keyBy('id'));
    expect($rows[$v1]->superseded_at)->not->toBeNull()
        ->and($rows[$v1]->superseded_by_triage_assessment_id)->toBe($v2)
        ->and($rows[$v1]->triage_priority)->toBe('NORMAL')                 // original clinical content untouched
        ->and($rows[$v2]->supersedes_triage_assessment_id)->toBe($v1)
        ->and($rows[$v2]->superseded_at)->toBeNull()
        ->and($rows[$v2]->triage_priority)->toBe('EMERGENCY')
        ->and($rows[$v2]->reason)->toBe('chest pain worsened')            // clinical data ON the row
        ->and($rows[$v2]->encounter_id)->toBe($encounterId);             // context copied

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', TriageAssessmentCorrected::class)->first());
    expect($outbox->event_name)->toBe('clinical.TriageAssessmentCorrected');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'triageAssessmentId', 'supersedesTriageAssessmentId', 'triagePriority', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['triagePriority'])->toBe('EMERGENCY')
        ->and($payload['supersedesTriageAssessmentId'])->toBe($v1);
    expect((string) $outbox->payload)->not->toContain('worsened'); // reason is PHI, never on the event

    // audit: clinical.triage.corrected, reason null (rationale lives on the row)
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.triage.corrected')->first());
    expect($audit->target_id)->toBe($v2)->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('the "current triage" is the latest non-superseded row after a re-triage chain', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $v1 = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, ['triagePriority' => 'LOW']);
    $v2 = correctTriage((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, ['triagePriority' => 'HIGH']);
    $v3 = correctTriage((string) $tenant->id, (string) $facility->id, $v2, (string) $user->id, ['triagePriority' => 'EMERGENCY']);

    $current = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')
        ->where('encounter_id', $encounterId)->whereNull('superseded_at')->get());
    expect($current)->toHaveCount(1)
        ->and($current->first()->id)->toBe($v3)
        ->and($current->first()->triage_priority)->toBe('EMERGENCY');
});

test('re-triage is NOT encounter-state-gated — it works after the encounter is COMPLETED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $v1 = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    $v2 = correctTriage((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, ['reason' => 'coding correction after review']);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->where('id', $v2)->exists()))->toBeTrue();
});

test('correcting an already-superseded assessment -> TriageAssessmentAlreadySuperseded (409) — chain forward', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $v1 = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    correctTriage((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id);

    try {
        correctTriage((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id, ['reason' => 'second attempt']);
        test()->fail('expected TriageAssessmentAlreadySuperseded');
    } catch (TriageAssessmentAlreadySuperseded $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('triage_assessment_already_superseded');
    }
});

test('an unknown / cross-tenant original -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    expect(fn () => correctTriage((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

// ── authorization ──────────────────────────────────────────────────

test('correction uses clinical.triage.record — a caller with only clinical.triage.read cannot correct; there is no .correct / .manage key', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.triage.read',
    ]);
    $v1 = seedTriage((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => correctTriage((string) $tenant->id, (string) $facility->id, $v1, (string) $user->id))
        ->toThrow(AuthorizationException::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->count()))->toBe(1);

    expect(PermissionRegistry::all())
        ->not->toContain('clinical.triage.correct')
        ->not->toContain('clinical.triage.manage')
        ->not->toContain('clinical.triage.update');
});

test('per-assessment facility scope: a caller not assigned at the assessment facility is denied', function () {
    ['tenant' => $tenant, 'user' => $user] = clinicalFixture();
    $v1 = seedTriage((string) $tenant->id, (string) Str::uuid7(), (string) $user->id);

    expect(fn () => correctTriage((string) $tenant->id, null, $v1, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});
