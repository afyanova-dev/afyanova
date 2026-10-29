<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectDiagnosis;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.3) unit gate — the CorrectDiagnosis command DTO (IC §3, §5, §18;
 | ADR-139 D4). A correction is a NEW record; there is NO Update / Resolve /
 | Cancel / Void command.
 */

function correctDiagnosisArgs(array $overrides = []): array
{
    return array_merge([
        'newDiagnosisId' => (string) Str::uuid7(),
        'supersedesDiagnosisId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'coding' => DiagnosisCode::of(code: 'B50.9', codeSystem: 'ICD-10'),
        'description' => 'Plasmodium falciparum malaria',
        'reason' => 'species confirmed',
    ], $overrides);
}

test('it is a per-subject command — subjectId is the diagnosis being corrected, ability "correct"', function () {
    $args = correctDiagnosisArgs();
    $c = new CorrectDiagnosis(...$args);

    expect($c)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($c)->toBeInstanceOf(Auditable::class)
        ->and($c->policyAbility())->toBe('correct')
        ->and($c->subjectId())->toBe($args['supersedesDiagnosisId'])
        ->and($c->clinicalStatus)->toBe('WORKING');
});

test('auditEntry() -> clinical.diagnoses.corrected, targets the NEW diagnosis, reason NOT on the audit (ADR-139 D11)', function () {
    $args = correctDiagnosisArgs();
    $entry = (new CorrectDiagnosis(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.diagnoses.corrected')
        ->and($entry->targetType)->toBe('diagnosis')
        ->and($entry->targetId)->toBe($args['newDiagnosisId'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('reason is mandatory', function () {
    expect(fn () => new CorrectDiagnosis(...correctDiagnosisArgs(['reason' => '  '])))
        ->toThrow(InvalidArgumentException::class);
});

test('a diagnosis cannot supersede itself', function () {
    $id = (string) Str::uuid7();
    expect(fn () => new CorrectDiagnosis(...correctDiagnosisArgs([
        'newDiagnosisId' => $id, 'supersedesDiagnosisId' => $id,
    ])))->toThrow(InvalidArgumentException::class);
});

test('it rejects malformed uuids, a blank description and a future occurred_at', function () {
    expect(fn () => new CorrectDiagnosis(...correctDiagnosisArgs(['newDiagnosisId' => 'nope'])))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new CorrectDiagnosis(...correctDiagnosisArgs(['description' => ' '])))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new CorrectDiagnosis(...correctDiagnosisArgs([
        'occurredAt' => CarbonImmutable::now()->addHour()->toISOString(),
    ])))->toThrow(InvalidArgumentException::class, 'future');
});

test('there is no Update / Resolve / Cancel / Void command class in the Clinical diagnoses command set', function () {
    $commands = array_map(
        static fn (string $f): string => basename($f, '.php'),
        glob(base_path('app/Domains/Clinical/Application/Command/*Diagnosis*.php')) ?: [],
    );

    expect($commands)->toEqualCanonicalizing([
        'RecordDiagnosis', 'RecordDiagnosisHandler', 'RecordDiagnosisResult',
        'CorrectDiagnosis', 'CorrectDiagnosisHandler',
    ]);
    foreach ($commands as $c) {
        foreach (['Update', 'Resolve', 'Cancel', 'Void'] as $forbidden) {
            expect($c)->not->toContain($forbidden);
        }
    }
});

test('the command is final readonly (IC §5)', function () {
    $r = new ReflectionClass(CorrectDiagnosis::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
