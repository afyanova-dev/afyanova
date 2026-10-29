<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordDiagnosis;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.3) unit gate — the RecordDiagnosis command DTO
 | (IC §3, §5, §18; ADR-132, ADR-139).
 */

function recordDiagnosisArgs(array $overrides = []): array
{
    return array_merge([
        'diagnosisId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(),
        'encounterId' => (string) Str::uuid7(),
        'coding' => DiagnosisCode::of(code: 'B54', codeSystem: 'ICD-10'),
        'description' => 'Unspecified malaria',
    ], $overrides);
}

test('it is a class-level create command — subjectId null, ability "record", Auditable + AuthorizesAgainst', function () {
    $c = new RecordDiagnosis(...recordDiagnosisArgs());

    expect($c)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($c)->toBeInstanceOf(Auditable::class)
        ->and($c->policyAbility())->toBe('record')
        ->and($c->subjectId())->toBeNull()
        ->and($c->clinicalStatus)->toBe('WORKING'); // default
});

test('auditEntry() -> clinical.diagnoses.recorded; no code / description in metadata', function () {
    $args = recordDiagnosisArgs();
    $entry = (new RecordDiagnosis(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.diagnoses.recorded')
        ->and($entry->actorId)->toBe($args['actorId'])
        ->and($entry->targetType)->toBe('diagnosis')
        ->and($entry->targetId)->toBe($args['diagnosisId'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it rejects malformed uuids (IC §3)', function (string $field) {
    expect(fn () => new RecordDiagnosis(...recordDiagnosisArgs([$field => 'nope'])))->toThrow(InvalidArgumentException::class);
})->with(['diagnosisId', 'actorId', 'patientId', 'encounterId']);

test('it rejects a blank description / blank clinical_status', function (array $overrides) {
    expect(fn () => new RecordDiagnosis(...recordDiagnosisArgs($overrides)))->toThrow(InvalidArgumentException::class);
})->with([
    'blank description' => [['description' => '   ']],
    'blank status' => [['clinicalStatus' => '  ']],
]);

test('it rejects a future occurred_at', function () {
    expect(fn () => new RecordDiagnosis(...recordDiagnosisArgs([
        'occurredAt' => CarbonImmutable::now()->addDay()->toISOString(),
    ])))->toThrow(InvalidArgumentException::class, 'future');
});

test('backdating > 24h needs a reason (-> audit row); within 24h needs none', function () {
    $backdated = CarbonImmutable::now()->subHours(30)->toISOString();

    expect(fn () => new RecordDiagnosis(...recordDiagnosisArgs(['occurredAt' => $backdated])))
        ->toThrow(InvalidArgumentException::class, 'reason');

    $c = new RecordDiagnosis(...recordDiagnosisArgs(['occurredAt' => $backdated, 'reason' => 'documented from referral letter']));
    expect($c->auditEntry()->reason)->toBe('documented from referral letter');

    $recent = new RecordDiagnosis(...recordDiagnosisArgs(['occurredAt' => CarbonImmutable::now()->subHours(2)->toISOString()]));
    expect($recent->auditEntry()->reason)->toBeNull();
});

test('an open clinical_status string is accepted (no enum, behaviour-neutral)', function () {
    expect((new RecordDiagnosis(...recordDiagnosisArgs(['clinicalStatus' => 'DIFFERENTIAL'])))->clinicalStatus)->toBe('DIFFERENTIAL');
});

test('the command is final readonly (IC §5)', function () {
    $r = new ReflectionClass(RecordDiagnosis::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
