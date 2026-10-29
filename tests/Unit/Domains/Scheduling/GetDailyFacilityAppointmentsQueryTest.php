<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Scheduling;

use App\Domains\Scheduling\Application\Query\GetDailyFacilityAppointments;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/*
 | Increment C unit gate — the GetDailyFacilityAppointments query DTO
 | (IC §3, §6.1, §6.2, §17.5; 22-scheduling-and-appointments-domain.md §285; ADR-207).
 */

test('it is a #[ProtectedQuery] with ability "view" and subjectType Appointment::class', function () {
    $attributes = (new ReflectionClass(GetDailyFacilityAppointments::class))->getAttributes(ProtectedQuery::class);

    expect($attributes)->toHaveCount(1);

    /** @var ProtectedQuery $protected */
    $protected = $attributes[0]->newInstance();

    expect($protected->ability)->toBe('view')
        ->and($protected->subjectType)->toBe(Appointment::class);
});

test('the query DTO is final readonly (IC §6)', function () {
    $reflection = new ReflectionClass(GetDailyFacilityAppointments::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});

test('it carries facilityId and date', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(GetDailyFacilityAppointments::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['facilityId', 'date']);
});

test('it accepts a valid UUID and date', function () {
    $facilityId = (string) Str::uuid7();
    $date = CarbonImmutable::parse('2026-09-13');

    $query = new GetDailyFacilityAppointments($facilityId, $date);

    expect($query->facilityId)->toBe($facilityId)
        ->and($query->date)->toBe($date);
});

test('it rejects a malformed facilityId (IC §3)', function () {
    $date = CarbonImmutable::parse('2026-09-13');

    expect(fn () => new GetDailyFacilityAppointments('not-a-uuid', $date))->toThrow(InvalidArgumentException::class);
    expect(fn () => new GetDailyFacilityAppointments('', $date))->toThrow(InvalidArgumentException::class);
});
