<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Query\GetEncounter;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;

/*
 | PHASE 5 (E.12) unit gate — the GetEncounter query DTO
 | (IC §3, §6.1, §6.2, §17.5; ADR-124, ADR-130).
 */

test('it is a #[ProtectedQuery] with ability "view" and subjectType Encounter::class', function () {
    $attributes = (new ReflectionClass(GetEncounter::class))->getAttributes(ProtectedQuery::class);

    expect($attributes)->toHaveCount(1);

    /** @var ProtectedQuery $protected */
    $protected = $attributes[0]->newInstance();

    expect($protected->ability)->toBe('view')
        ->and($protected->subjectType)->toBe(Encounter::class);
});

test('the query DTO is final readonly (IC §6)', function () {
    $reflection = new ReflectionClass(GetEncounter::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

test('it carries exactly encounterId — no facility / tenant / actor input', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(GetEncounter::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['encounterId']);
});

test('it accepts a valid UUID and exposes it', function () {
    $id = (string) Str::uuid7();

    expect((new GetEncounter($id))->encounterId)->toBe($id);
});

test('it rejects a malformed encounterId (IC §3)', function () {
    expect(fn () => new GetEncounter('not-a-uuid'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new GetEncounter(''))->toThrow(InvalidArgumentException::class);
});
