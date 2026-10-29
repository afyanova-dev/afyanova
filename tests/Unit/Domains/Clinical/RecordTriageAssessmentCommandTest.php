<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordTriageAssessment;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.9) unit gate — the RecordTriageAssessment command DTO
 | (IC §3, §5, §18; ADR-132, ADR-145).
 */

function validTriageArgs(array $overrides = []): array
{
    return array_merge([
        'triageAssessmentId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(),
        'encounterId' => (string) Str::uuid7(),
        'chiefComplaint' => 'Fever and cough',
        'triagePriority' => 'URGENT',
    ], $overrides);
}

test('it is a class-level record command — subjectId null, ability "record", Auditable + AuthorizesAgainst', function () {
    $command = new RecordTriageAssessment(...validTriageArgs());

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('record')
        ->and($command->subjectId())->toBeNull()
        ->and($command->authoredContext)->toBe('CLINICIAN'); // default (ADR-145 D-M)
});

test('auditEntry() targets triage_assessment with action clinical.triage.recorded and empty metadata', function () {
    $args = validTriageArgs();
    $entry = (new RecordTriageAssessment(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.triage.recorded')
        ->and($entry->actorId)->toBe($args['actorId'])
        ->and($entry->targetType)->toBe('triage_assessment')
        ->and($entry->targetId)->toBe($args['triageAssessmentId'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it rejects malformed uuids (IC §3), including the optional source vitals id', function (string $field) {
    expect(fn () => new RecordTriageAssessment(...validTriageArgs([$field => 'nope'])))
        ->toThrow(InvalidArgumentException::class);
})->with(['triageAssessmentId', 'actorId', 'patientId', 'encounterId', 'sourceVitalsObservationId']);

test('it accepts a null source vitals id and a valid uuid one', function () {
    expect((new RecordTriageAssessment(...validTriageArgs(['sourceVitalsObservationId' => null])))->sourceVitalsObservationId)->toBeNull();
    $vid = (string) Str::uuid7();
    expect((new RecordTriageAssessment(...validTriageArgs(['sourceVitalsObservationId' => $vid])))->sourceVitalsObservationId)->toBe($vid);
});

test('it rejects a blank chief complaint', function () {
    expect(fn () => new RecordTriageAssessment(...validTriageArgs(['chiefComplaint' => '  '])))
        ->toThrow(InvalidArgumentException::class, 'chiefComplaint');
});

test('triagePriority accepts the queueing §21 set (any case) and rejects the rest', function () {
    foreach (['EMERGENCY', 'urgent', 'High', 'NORMAL', 'low'] as $ok) {
        expect(new RecordTriageAssessment(...validTriageArgs(['triagePriority' => $ok]))->triagePriority)->toBe($ok);
    }
    foreach (['CRITICAL', 'ESI-2', 'RED', ''] as $bad) {
        expect(fn () => new RecordTriageAssessment(...validTriageArgs(['triagePriority' => $bad])))
            ->toThrow(InvalidArgumentException::class, 'EMERGENCY / URGENT / HIGH / NORMAL / LOW');
    }
});

test('authoredContext accepts CLINICIAN or NURSING and rejects anything else (ADR-145 D-M)', function () {
    expect((new RecordTriageAssessment(...validTriageArgs(['authoredContext' => 'NURSING'])))->authoredContext)->toBe('NURSING');
    foreach (['PROCEDURE', 'nurse', ''] as $bad) {
        expect(fn () => new RecordTriageAssessment(...validTriageArgs(['authoredContext' => $bad])))
            ->toThrow(InvalidArgumentException::class, 'CLINICIAN or NURSING');
    }
});

test('it rejects a future occurred_at', function () {
    expect(fn () => new RecordTriageAssessment(...validTriageArgs([
        'occurredAt' => CarbonImmutable::now()->addDay()->toISOString(),
    ])))->toThrow(InvalidArgumentException::class, 'future');
});

test('backdating more than 24h without a reason is rejected; with a reason it lands on the audit entry', function () {
    $backdated = CarbonImmutable::now()->subHours(25)->toISOString();

    expect(fn () => new RecordTriageAssessment(...validTriageArgs(['occurredAt' => $backdated])))
        ->toThrow(InvalidArgumentException::class, 'reason');

    $command = new RecordTriageAssessment(...validTriageArgs([
        'occurredAt' => $backdated, 'reason' => 'triaged from the ambulance run sheet',
    ]));
    expect($command->auditEntry()->reason)->toBe('triaged from the ambulance run sheet');
});

test('the command is final readonly (IC §5)', function () {
    $reflection = new ReflectionClass(RecordTriageAssessment::class);
    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
});
