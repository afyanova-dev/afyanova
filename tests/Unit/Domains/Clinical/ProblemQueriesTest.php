<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetPatientProblems;
use App\Domains\Clinical\Application\Query\GetProblem;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\ValueObject\ProblemStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.4) unit gate — the GetProblem / GetPatientProblems query DTOs
 | (IC §3, §6.2; ADR-140 D-L). Exactly two read capabilities; both
 | #[ProtectedQuery(view, Problem)].
 */

test('GetProblem is #[ProtectedQuery(view, Problem)] and UUID-validates its id', function () {
    $attr = (new ReflectionClass(GetProblem::class))->getAttributes(ProtectedQuery::class)[0]->newInstance();
    expect($attr->ability)->toBe('view')->and($attr->subjectType)->toBe(Problem::class);

    expect(fn () => new GetProblem('nope'))->toThrow(InvalidArgumentException::class);
    expect((new GetProblem($id = (string) Str::uuid7()))->problemId)->toBe($id);
});

test('GetPatientProblems is #[ProtectedQuery(view, Problem)] and UUID-validates the patient id', function () {
    $attr = (new ReflectionClass(GetPatientProblems::class))->getAttributes(ProtectedQuery::class)[0]->newInstance();
    expect($attr->ability)->toBe('view')->and($attr->subjectType)->toBe(Problem::class);

    expect(fn () => new GetPatientProblems('nope'))->toThrow(InvalidArgumentException::class);
});

test('GetPatientProblems status filter: null / a valid ProblemStatus value accepted; an unknown string is rejected', function () {
    expect((new GetPatientProblems((string) Str::uuid7()))->status)->toBeNull();
    expect((new GetPatientProblems((string) Str::uuid7(), ProblemStatus::ACTIVE))->status)->toBe('ACTIVE');
    expect((new GetPatientProblems((string) Str::uuid7(), 'RESOLVED'))->status)->toBe(ProblemStatus::RESOLVED);

    expect(fn () => new GetPatientProblems((string) Str::uuid7(), 'CHRONIC'))->toThrow(InvalidArgumentException::class);
});

test('GetPatientProblems takes NO page / perPage / cursor parameter (ADR-140 D-L / clarification lock Decision 3)', function () {
    $params = array_map(
        fn (ReflectionParameter $p) => $p->getName(),
        (new ReflectionClass(GetPatientProblems::class))->getConstructor()->getParameters(),
    );
    expect($params)->toBe(['patientId', 'status'])
        ->and($params)->not->toContain('page')
        ->and($params)->not->toContain('perPage')
        ->and($params)->not->toContain('cursor');
});

test('both queries are final readonly', function () {
    foreach ([GetProblem::class, GetPatientProblems::class] as $class) {
        $r = new ReflectionClass($class);
        expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
    }
});
