<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\TriageAssessmentCorrected;
use App\Domains\Clinical\Contracts\Event\TriageAssessmentRecorded;
use App\Domains\Clinical\Domain\Exception\TriageAssessmentAlreadySuperseded;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Clinical\Domain\ValueObject\TriagePriority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.9) unit gate — the TriageAssessment aggregate (ADR-105, ADR-132,
 | ADR-139 D4 superseding model, ADR-145). In-memory only — no DB.
 */

function makeTriage(array $o = []): TriageAssessment
{
    return TriageAssessment::record(
        id: $o['id'] ?? (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: $o['authoredContext'] ?? TriageAssessment::AUTHORED_CONTEXT_NURSING,
        chiefComplaint: $o['chiefComplaint'] ?? 'Chest pain',
        triagePriority: $o['triagePriority'] ?? TriagePriority::URGENT,
        acuity: $o['acuity'] ?? null,
        consciousness: $o['consciousness'] ?? null,
        sourceVitalsObservationId: $o['sourceVitalsObservationId'] ?? null,
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );
}

// ── recording ──────────────────────────────────────────────────────

test('record() produces a RECORDED assessment at version 0, no status column, one TriageAssessmentRecorded', function () {
    $t = makeTriage(['triagePriority' => 'emergency', 'chiefComplaint' => '  Collapsed at home  ']);

    expect((int) $t->version)->toBe(0)
        ->and($t->triage_priority)->toBe('EMERGENCY')          // normalized
        ->and($t->chief_complaint)->toBe('Collapsed at home')  // trimmed
        ->and($t->authored_context)->toBe('NURSING')
        ->and($t->superseded_at)->toBeNull()
        ->and($t->supersedes_triage_assessment_id)->toBeNull()
        ->and($t->isSuperseded())->toBeFalse()
        ->and($t->isCorrection())->toBeFalse()
        ->and(in_array('status', $t->getFillable(), true))->toBeFalse();

    $events = $t->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TriageAssessmentRecorded::class)
        ->and($events[0]->triagePriority)->toBe('EMERGENCY')
        ->and($events[0]->triageAssessmentId)->toBe((string) $t->id)
        // PHI-free: no chief complaint / acuity / consciousness / reason on the event (ADR-145 D-J)
        ->and(property_exists($events[0], 'chiefComplaint'))->toBeFalse()
        ->and(property_exists($events[0], 'acuity'))->toBeFalse()
        ->and(property_exists($events[0], 'consciousness'))->toBeFalse()
        ->and(property_exists($events[0], 'reason'))->toBeFalse();
});

test('record() accepts CLINICIAN and NURSING authored_context and rejects anything else (ADR-145 D-M)', function () {
    expect(makeTriage(['authoredContext' => 'CLINICIAN'])->authored_context)->toBe('CLINICIAN')
        ->and(makeTriage(['authoredContext' => 'NURSING'])->authored_context)->toBe('NURSING');

    foreach (['PROCEDURE', 'nurse', ''] as $bad) {
        expect(fn () => makeTriage(['authoredContext' => $bad]))
            ->toThrow(InvalidArgumentException::class, 'authored_context');
    }
});

test('record() rejects a blank chief complaint and an out-of-set priority', function () {
    expect(fn () => makeTriage(['chiefComplaint' => '   ']))
        ->toThrow(InvalidArgumentException::class, 'chiefComplaint');
    expect(fn () => makeTriage(['triagePriority' => 'CRITICAL']))
        ->toThrow(InvalidArgumentException::class, 'Invalid TriagePriority');
});

test('acuity and consciousness are open shape-validated strings: blanks normalize to null, over-long is rejected (ADR-145 O-1)', function () {
    $t = makeTriage(['acuity' => '  ', 'consciousness' => 'GCS 14']);
    expect($t->acuity)->toBeNull()->and($t->consciousness)->toBe('GCS 14');

    expect(fn () => makeTriage(['acuity' => str_repeat('x', 200)]))
        ->toThrow(InvalidArgumentException::class, 'acuity');
});

test('AUTHORED_CONTEXTS is exactly {CLINICIAN, NURSING}', function () {
    expect(TriageAssessment::AUTHORED_CONTEXTS)->toBe(['CLINICIAN', 'NURSING']);
});

// ── correction / re-triage (superseding model) ─────────────────────

test('correcting() builds a NEW assessment carrying supersedes + reason, copies context, records TriageAssessmentCorrected', function () {
    $original = makeTriage(['triagePriority' => 'NORMAL']);
    $original->releaseEvents();

    $correction = TriageAssessment::correcting(
        id: (string) Str::uuid7(),
        original: $original,
        authorUserId: (string) Str::uuid7(),
        authoredContext: TriageAssessment::AUTHORED_CONTEXT_CLINICIAN,
        chiefComplaint: 'Chest pain, now radiating to arm',
        triagePriority: 'EMERGENCY',
        acuity: null,
        consciousness: null,
        sourceVitalsObservationId: null,
        reason: 'patient deteriorated in the waiting room',
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );

    expect($correction->supersedes_triage_assessment_id)->toBe((string) $original->id)
        ->and($correction->reason)->toBe('patient deteriorated in the waiting room')
        ->and($correction->triage_priority)->toBe('EMERGENCY')
        ->and($correction->tenant_id)->toBe($original->tenant_id)        // context copied
        ->and($correction->encounter_id)->toBe($original->encounter_id)
        ->and($correction->patient_id)->toBe($original->patient_id)
        ->and($correction->facility_id)->toBe($original->facility_id)
        ->and($correction->isCorrection())->toBeTrue();

    $events = $correction->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TriageAssessmentCorrected::class)
        ->and($events[0]->supersedesTriageAssessmentId)->toBe((string) $original->id)
        ->and($events[0]->triagePriority)->toBe('EMERGENCY')
        ->and(property_exists($events[0], 'reason'))->toBeFalse()
        ->and(property_exists($events[0], 'chiefComplaint'))->toBeFalse();
});

test('correcting() requires a non-blank reason', function () {
    $original = makeTriage();
    expect(fn () => TriageAssessment::correcting(
        id: (string) Str::uuid7(), original: $original, authorUserId: (string) Str::uuid7(),
        authoredContext: 'NURSING', chiefComplaint: 'x', triagePriority: 'LOW',
        acuity: null, consciousness: null, sourceVitalsObservationId: null,
        reason: '   ', occurredAt: CarbonImmutable::now(), recordedAt: CarbonImmutable::now(),
    ))->toThrow(InvalidArgumentException::class, 'reason is required');
});

test('markSupersededBy() is a one-time stamp — a second call is rejected', function () {
    $original = makeTriage();
    $original->releaseEvents(); // drain the TriageAssessmentRecorded from creation
    $bodyPriority = $original->triage_priority;

    $original->markSupersededBy((string) Str::uuid7());
    expect($original->superseded_at)->not->toBeNull()
        ->and($original->isSuperseded())->toBeTrue()
        ->and($original->triage_priority)->toBe($bodyPriority)  // clinical content untouched
        ->and($original->releaseEvents())->toBe([]);            // no event on the stamped original

    expect(fn () => $original->markSupersededBy((string) Str::uuid7()))
        ->toThrow(TriageAssessmentAlreadySuperseded::class);
});

test('the aggregate is final and there is no persisted status case', function () {
    $reflection = new ReflectionClass(TriageAssessment::class);
    expect($reflection->isFinal())->toBeTrue()
        ->and(in_array('status', (new TriageAssessment)->getFillable(), true))->toBeFalse();
});
