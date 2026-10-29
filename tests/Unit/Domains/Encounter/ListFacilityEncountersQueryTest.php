<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Encounter;

use App\Domains\Encounter\Application\Query\ListFacilityEncounters;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/*
 | Increment A (E.13) unit gate — the ListFacilityEncounters query DTO
 | (IC §3, §6.1, §6.2, §17.5; 11-encounter-domain.md §99, §102; ADR-130).
 */

test('it is a #[ProtectedQuery] with ability "view" and subjectType Encounter::class', function () {
    $attributes = (new ReflectionClass(ListFacilityEncounters::class))->getAttributes(ProtectedQuery::class);

    expect($attributes)->toHaveCount(1);

    /** @var ProtectedQuery $protected */
    $protected = $attributes[0]->newInstance();

    expect($protected->ability)->toBe('view')
        ->and($protected->subjectType)->toBe(Encounter::class);
});

test('the query DTO is final readonly (IC §6)', function () {
    $reflection = new ReflectionClass(ListFacilityEncounters::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

test('it carries facilityId, date, statuses, limit', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(ListFacilityEncounters::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['facilityId', 'date', 'statuses', 'limit']);
});

test('it accepts a valid UUID and exposes it with defaults', function () {
    $facilityId = (string) Str::uuid7();

    $query = new ListFacilityEncounters($facilityId);

    expect($query->facilityId)->toBe($facilityId)
        ->and($query->date)->toBeNull()
        ->and($query->statuses)->toBeNull()
        ->and($query->limit)->toBe(100);
});

test('it parses string date into CarbonImmutable', function () {
    $facilityId = (string) Str::uuid7();

    $query = new ListFacilityEncounters($facilityId, '2026-09-13');

    expect($query->date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($query->date->toDateString())->toBe('2026-09-13');
});

test('it accepts CarbonImmutable date directly', function () {
    $facilityId = (string) Str::uuid7();
    $date = CarbonImmutable::parse('2026-09-13');

    $query = new ListFacilityEncounters($facilityId, $date);

    expect($query->date)->toBe($date);
});

test('it accepts status filters', function () {
    $facilityId = (string) Str::uuid7();

    $query = new ListFacilityEncounters(
        facilityId: $facilityId,
        statuses: [EncounterStatus::Arrived, EncounterStatus::Active],
    );

    expect($query->statuses)->toBe([EncounterStatus::Arrived, EncounterStatus::Active]);
});

test('it rejects a malformed facilityId (IC §3)', function () {
    expect(fn () => new ListFacilityEncounters('not-a-uuid'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ListFacilityEncounters(''))->toThrow(InvalidArgumentException::class);
});

test('it rejects limit less than 1 or greater than 500', function () {
    $facilityId = (string) Str::uuid7();

    expect(fn () => new ListFacilityEncounters($facilityId, limit: 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ListFacilityEncounters($facilityId, limit: 501))->toThrow(InvalidArgumentException::class);
});
