<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Patient;

use App\Domains\Patient\Application\Query\GetPatientSummaries;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/*
 | Increment B unit gate — GetPatientSummaries query DTO
 | (IC §3, §6.1, §6.2, §17.5; 10-patient-domain.md Invariant 12; ADR-125).
 */

test('it is a #[ProtectedQuery] with ability "view" and subjectType Patient::class', function () {
    $attributes = (new ReflectionClass(GetPatientSummaries::class))->getAttributes(ProtectedQuery::class);

    expect($attributes)->toHaveCount(1);

    /** @var ProtectedQuery $protected */
    $protected = $attributes[0]->newInstance();

    expect($protected->ability)->toBe('view')
        ->and($protected->subjectType)->toBe(Patient::class);
});

test('the query DTO is final readonly (IC §6)', function () {
    $reflection = new ReflectionClass(GetPatientSummaries::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

test('it carries patientIds', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(GetPatientSummaries::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['patientIds']);
});

test('it accepts valid UUIDs and deduplicates them', function () {
    $uuid1 = (string) Str::uuid7();
    $uuid2 = (string) Str::uuid7();

    $query = new GetPatientSummaries([$uuid1, $uuid2, $uuid1]);

    expect($query->patientIds)->toHaveCount(2)
        ->and($query->patientIds)->toBe([$uuid1, $uuid2]);
});

test('it filters out malformed non-UUID strings', function () {
    $uuid1 = (string) Str::uuid7();

    $query = new GetPatientSummaries([$uuid1, 'not-a-uuid', '', '12345']);

    expect($query->patientIds)->toBe([$uuid1]);
});

test('it handles empty array gracefully', function () {
    $query = new GetPatientSummaries([]);

    expect($query->patientIds)->toBe([]);
});
