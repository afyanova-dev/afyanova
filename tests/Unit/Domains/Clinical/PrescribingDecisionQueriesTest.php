<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetPatientPrescribingDecisions;
use App\Domains\Clinical\Application\Query\GetPrescribingDecision;
use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Domains\Clinical\Domain\ValueObject\PrescribingDecisionStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;

test('GetPrescribingDecision is #[ProtectedQuery(view, PrescribingDecision)] and UUID-validates its id', function () {
    $attr = (new ReflectionClass(GetPrescribingDecision::class))->getAttributes(ProtectedQuery::class)[0]->newInstance();
    expect($attr->ability)->toBe('view')->and($attr->subjectType)->toBe(PrescribingDecision::class);

    expect(fn () => new GetPrescribingDecision('invalid-uuid'))->toThrow(InvalidArgumentException::class);
    expect((new GetPrescribingDecision($id = (string) Str::uuid7()))->prescribingDecisionId)->toBe($id);
});

test('GetPatientPrescribingDecisions is #[ProtectedQuery(view, PrescribingDecision)] and UUID-validates patient id', function () {
    $attr = (new ReflectionClass(GetPatientPrescribingDecisions::class))->getAttributes(ProtectedQuery::class)[0]->newInstance();
    expect($attr->ability)->toBe('view')->and($attr->subjectType)->toBe(PrescribingDecision::class);

    expect(fn () => new GetPatientPrescribingDecisions('not-a-uuid'))->toThrow(InvalidArgumentException::class);
});

test('GetPatientPrescribingDecisions validates status filter', function () {
    expect((new GetPatientPrescribingDecisions((string) Str::uuid7()))->status)->toBeNull();
    expect((new GetPatientPrescribingDecisions((string) Str::uuid7(), PrescribingDecisionStatus::ACTIVE))->status)->toBe('ACTIVE');
    expect((new GetPatientPrescribingDecisions((string) Str::uuid7(), 'DISCONTINUED'))->status)->toBe(PrescribingDecisionStatus::DISCONTINUED);

    expect(fn () => new GetPatientPrescribingDecisions((string) Str::uuid7(), 'INVALID'))->toThrow(InvalidArgumentException::class);
});

test('both PrescribingDecision queries are final readonly', function () {
    foreach ([GetPrescribingDecision::class, GetPatientPrescribingDecisions::class] as $class) {
        $r = new ReflectionClass($class);
        expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
    }
});
