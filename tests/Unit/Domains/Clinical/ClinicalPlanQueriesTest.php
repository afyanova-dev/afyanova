<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetClinicalPlan;
use App\Domains\Clinical\Application\Query\GetPatientClinicalPlans;
use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;

test('GetClinicalPlan is #[ProtectedQuery(view, ClinicalPlan)] and UUID-validates its id', function () {
    $attr = (new ReflectionClass(GetClinicalPlan::class))->getAttributes(ProtectedQuery::class)[0]->newInstance();
    expect($attr->ability)->toBe('view')->and($attr->subjectType)->toBe(ClinicalPlan::class);

    expect(fn () => new GetClinicalPlan('invalid-uuid'))->toThrow(InvalidArgumentException::class);
    expect((new GetClinicalPlan($id = (string) Str::uuid7()))->planId)->toBe($id);
});

test('GetPatientClinicalPlans is #[ProtectedQuery(view, ClinicalPlan)] and UUID-validates patient id', function () {
    $attr = (new ReflectionClass(GetPatientClinicalPlans::class))->getAttributes(ProtectedQuery::class)[0]->newInstance();
    expect($attr->ability)->toBe('view')->and($attr->subjectType)->toBe(ClinicalPlan::class);

    expect(fn () => new GetPatientClinicalPlans('not-a-uuid'))->toThrow(InvalidArgumentException::class);
});

test('GetPatientClinicalPlans validates status filter', function () {
    expect((new GetPatientClinicalPlans((string) Str::uuid7()))->status)->toBeNull();
    expect((new GetPatientClinicalPlans((string) Str::uuid7(), ClinicalPlanStatus::ACTIVE))->status)->toBe('ACTIVE');
    expect((new GetPatientClinicalPlans((string) Str::uuid7(), 'COMPLETED'))->status)->toBe(ClinicalPlanStatus::COMPLETED);

    expect(fn () => new GetPatientClinicalPlans((string) Str::uuid7(), 'INVALID'))->toThrow(InvalidArgumentException::class);
});

test('both ClinicalPlan queries are final readonly', function () {
    foreach ([GetClinicalPlan::class, GetPatientClinicalPlans::class] as $class) {
        $r = new ReflectionClass($class);
        expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
    }
});
