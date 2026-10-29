<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetAllergy;
use App\Domains\Clinical\Application\Query\GetPatientAllergies;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\ValueObject\AllergyStatus;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.5) unit gate — the allergy query DTOs (IC §3, §6.2; ADR-141 D-N / D-O).
 */

test('GetAllergy is #[ProtectedQuery(view, Allergy)] and UUID-validates its target', function () {
    $r = new ReflectionClass(GetAllergy::class);
    $attr = $r->getAttributes(ProtectedQuery::class)[0]->newInstance();

    expect($attr->ability)->toBe('view')
        ->and($attr->subjectType)->toBe(Allergy::class);

    $id = (string) Str::uuid7();
    expect((new GetAllergy($id))->allergyId)->toBe($id);

    expect(fn () => new GetAllergy('bad'))->toThrow(InvalidArgumentException::class);
});

test('GetPatientAllergies is #[ProtectedQuery(view, Allergy)] and UUID-validates patientId', function () {
    $r = new ReflectionClass(GetPatientAllergies::class);
    $attr = $r->getAttributes(ProtectedQuery::class)[0]->newInstance();

    expect($attr->ability)->toBe('view')
        ->and($attr->subjectType)->toBe(Allergy::class);

    $id = (string) Str::uuid7();
    expect((new GetPatientAllergies($id))->patientId)->toBe($id);

    expect(fn () => new GetPatientAllergies('bad'))->toThrow(InvalidArgumentException::class);
});

test('GetPatientAllergies status filters validate status values', function () {
    $id = (string) Str::uuid7();

    $q1 = new GetPatientAllergies($id, AllergyStatus::ACTIVE, AllergyVerificationStatus::CONFIRMED);
    expect($q1->clinicalStatus)->toBe('ACTIVE')
        ->and($q1->verificationStatus)->toBe('CONFIRMED');

    expect(fn () => new GetPatientAllergies($id, 'INVALID_STATUS'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new GetPatientAllergies($id, null, 'INVALID_VERIF'))
        ->toThrow(InvalidArgumentException::class);
});

test('GetPatientAllergies takes NO page / perPage / cursor parameter (ADR-141 D-N)', function () {
    $r = new ReflectionClass(GetPatientAllergies::class);
    $params = array_map(fn ($p) => $p->getName(), $r->getConstructor()->getParameters());

    expect($params)->toBe(['patientId', 'clinicalStatus', 'verificationStatus']);
});

test('both queries are final readonly', function () {
    foreach ([GetAllergy::class, GetPatientAllergies::class] as $class) {
        $r = new ReflectionClass($class);
        expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
    }
});
