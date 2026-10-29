<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetEncounterTriageAssessments;
use App\Domains\Clinical\Application\Query\GetTriageAssessment;
use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.9) unit gate — the two triage query DTOs + the
 | TriageAssessmentSummary result shape (IC §6.2; ADR-145 D-H).
 */

test('GetTriageAssessment is #[ProtectedQuery(view, TriageAssessment::class)], validates the id, final readonly', function () {
    $attr = (new ReflectionClass(GetTriageAssessment::class))->getAttributes(ProtectedQuery::class)[0]->newInstance();
    expect($attr->ability)->toBe('view')->and($attr->subjectType)->toBe(TriageAssessment::class);

    expect(fn () => new GetTriageAssessment('nope'))->toThrow(InvalidArgumentException::class);
    $id = (string) Str::uuid7();
    expect((new GetTriageAssessment($id))->triageAssessmentId)->toBe($id);

    $r = new ReflectionClass(GetTriageAssessment::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});

test('GetEncounterTriageAssessments is #[ProtectedQuery], validates the encounter id, defaults currentOnly false', function () {
    $attr = (new ReflectionClass(GetEncounterTriageAssessments::class))->getAttributes(ProtectedQuery::class)[0]->newInstance();
    expect($attr->ability)->toBe('view')->and($attr->subjectType)->toBe(TriageAssessment::class);

    expect(fn () => new GetEncounterTriageAssessments('nope'))->toThrow(InvalidArgumentException::class);
    $id = (string) Str::uuid7();
    $q = new GetEncounterTriageAssessments($id);
    expect($q->encounterId)->toBe($id)->and($q->currentOnly)->toBeFalse();
    expect((new GetEncounterTriageAssessments($id, true))->currentOnly)->toBeTrue();
});

test('TriageAssessmentSummary exposes exactly the ratified fields — ids + own fields + supersession chain; no vitals values, no resolved patient', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(TriageAssessmentSummary::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe([
        'triageAssessmentId', 'patientId', 'encounterId', 'facilityId',
        'authorUserId', 'authoredContext',
        'chiefComplaint', 'triagePriority', 'acuity', 'consciousness', 'sourceVitalsObservationId',
        'occurredAt', 'recordedAt',
        'supersedesTriageAssessmentId', 'supersededAt', 'supersededByTriageAssessmentId', 'correctionReason',
    ]);

    foreach (['heartRate', 'temperature', 'systolicBp', 'patientName', 'displayName', 'encounterNumber', 'destination', 'disposition', 'status'] as $forbidden) {
        expect($params)->not->toContain($forbidden);
    }

    $r = new ReflectionClass(TriageAssessmentSummary::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue()->and($r->getAttributes())->toBe([]);
});

test('the C.9 triage query set is exactly GetTriageAssessment + GetEncounterTriageAssessments (no patient-level / timeline / clin_rm_* — ADR-145 D-H)', function () {
    $queries = array_map(
        static fn (string $f): string => basename($f, '.php'),
        glob(base_path('app/Domains/Clinical/Application/Query/*Triage*.php')) ?: [],
    );

    expect($queries)->toEqualCanonicalizing([
        'GetTriageAssessment', 'GetTriageAssessmentHandler',
        'GetEncounterTriageAssessments', 'GetEncounterTriageAssessmentsHandler',
    ]);
});
