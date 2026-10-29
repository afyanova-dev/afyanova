<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetClinicalNote;
use App\Domains\Clinical\Contracts\Dto\ClinicalNoteSummary;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) unit gate — the GetClinicalNote query DTO + its
 | ClinicalNoteSummary result shape (IC §6.2; ADR-133, ADR-134).
 */

test('it is #[ProtectedQuery(view, ClinicalNote::class)]', function () {
    $attribute = (new ReflectionClass(GetClinicalNote::class))->getAttributes(ProtectedQuery::class)[0] ?? null;

    expect($attribute)->not->toBeNull();
    $protected = $attribute->newInstance();
    expect($protected->ability)->toBe('view')
        ->and($protected->subjectType)->toBe(ClinicalNote::class);
});

test('it validates the note id and is final readonly', function () {
    expect(fn () => new GetClinicalNote('nope'))->toThrow(InvalidArgumentException::class);

    $id = (string) Str::uuid7();
    expect((new GetClinicalNote($id))->clinicalNoteId)->toBe($id);

    $reflection = new ReflectionClass(GetClinicalNote::class);
    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
});

test('ClinicalNoteSummary exposes exactly the canonical fields — ids + own fields + body, NO resolved patient/encounter/clinical/financial data', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(ClinicalNoteSummary::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe([
        'clinicalNoteId', 'patientId', 'encounterId', 'facilityId',
        'authorUserId', 'authoredContext', 'noteType', 'title', 'body', 'status',
        'occurredAt', 'recordedAt', 'signedAt', 'signedByUserId', 'amendmentCount',
    ]);

    // no leakage of resolved cross-entity data
    foreach (['patientName', 'displayName', 'dateOfBirth', 'encounterNumber', 'encounterStatus', 'diagnosis', 'amount'] as $forbidden) {
        expect($params)->not->toContain($forbidden);
    }

    $reflection = new ReflectionClass(ClinicalNoteSummary::class);
    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
});

test('ClinicalNoteSummary is not #[TypeScript] (backend only — ADR-131)', function () {
    expect((new ReflectionClass(ClinicalNoteSummary::class))->getAttributes())->toBe([]);
});
