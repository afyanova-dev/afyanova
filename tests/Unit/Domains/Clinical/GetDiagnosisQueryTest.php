<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetDiagnosis;
use App\Domains\Clinical\Contracts\Dto\DiagnosisSummary;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.3) unit gate — the GetDiagnosis query DTO + its DiagnosisSummary
 | result shape (IC §6.2; ADR-139 D12).
 */

test('it is #[ProtectedQuery(view, Diagnosis::class)]', function () {
    $attr = (new ReflectionClass(GetDiagnosis::class))->getAttributes(ProtectedQuery::class)[0] ?? null;
    expect($attr)->not->toBeNull();

    $p = $attr->newInstance();
    expect($p->ability)->toBe('view')->and($p->subjectType)->toBe(Diagnosis::class);
});

test('it validates the diagnosis id and is final readonly', function () {
    expect(fn () => new GetDiagnosis('nope'))->toThrow(InvalidArgumentException::class);

    $id = (string) Str::uuid7();
    expect((new GetDiagnosis($id))->diagnosisId)->toBe($id);

    $r = new ReflectionClass(GetDiagnosis::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});

test('DiagnosisSummary exposes exactly the ratified fields — ids + own fields + supersession chain; no rank / resolved patient / encounter data', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(DiagnosisSummary::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe([
        'diagnosisId', 'patientId', 'encounterId', 'facilityId',
        'authorUserId', 'authoredContext',
        'code', 'codeSystem', 'description', 'clinicalStatus',
        'occurredAt', 'recordedAt',
        'supersedesDiagnosisId', 'supersededAt', 'supersededByDiagnosisId', 'correctionReason',
    ]);

    foreach (['rank', 'primary', 'secondary', 'onsetDate', 'resolvedAt', 'patientName', 'displayName', 'encounterNumber', 'problemId'] as $forbidden) {
        expect($params)->not->toContain($forbidden);
    }

    $r = new ReflectionClass(DiagnosisSummary::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue()->and($r->getAttributes())->toBe([]);
});

test('it is the only query in the Clinical diagnoses query set (no list / current / encounter / trend — ADR-139 D12)', function () {
    $queries = array_map(
        static fn (string $f): string => basename($f, '.php'),
        glob(base_path('app/Domains/Clinical/Application/Query/*Diagnos*.php')) ?: [],
    );

    expect($queries)->toEqualCanonicalizing(['GetDiagnosis', 'GetDiagnosisHandler']);
});
