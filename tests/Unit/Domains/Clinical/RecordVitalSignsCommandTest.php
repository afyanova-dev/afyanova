<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordVitalSigns;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.2) unit gate — the RecordVitalSigns command DTO
 | (IC §3, §5, §18; ADR-132, ADR-138).
 */

function recordArgs(array $overrides = []): array
{
    return array_merge([
        'vitalSignsObservationId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(),
        'encounterId' => (string) Str::uuid7(),
        'measurements' => VitalSigns::of(temperature: 37.0, heartRate: 72),
    ], $overrides);
}

test('it is a class-level create command — subjectId null, ability "record", Auditable + AuthorizesAgainst', function () {
    $c = new RecordVitalSigns(...recordArgs());

    expect($c)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($c)->toBeInstanceOf(Auditable::class)
        ->and($c->policyAbility())->toBe('record')
        ->and($c->subjectId())->toBeNull()
        ->and($c->authoredContext)->toBe(VitalSignsObservation::AUTHORED_CONTEXT_CLINICIAN); // default
});

test('auditEntry() -> clinical.vitals.recorded, no measurement values in metadata', function () {
    $args = recordArgs();
    $entry = (new RecordVitalSigns(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.vitals.recorded')
        ->and($entry->actorId)->toBe($args['actorId'])
        ->and($entry->targetType)->toBe('vital_signs_observation')
        ->and($entry->targetId)->toBe($args['vitalSignsObservationId'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it rejects malformed uuids (IC §3)', function (string $field) {
    expect(fn () => new RecordVitalSigns(...recordArgs([$field => 'nope'])))->toThrow(InvalidArgumentException::class);
})->with(['vitalSignsObservationId', 'actorId', 'patientId', 'encounterId']);

test('authoredContext accepts CLINICIAN or NURSING and rejects anything else', function () {
    expect((new RecordVitalSigns(...recordArgs(['authoredContext' => 'NURSING'])))->authoredContext)->toBe('NURSING');
    expect(fn () => new RecordVitalSigns(...recordArgs(['authoredContext' => 'DEVICE'])))->toThrow(InvalidArgumentException::class);
});

test('it rejects a future occurred_at', function () {
    expect(fn () => new RecordVitalSigns(...recordArgs([
        'occurredAt' => CarbonImmutable::now()->addHour()->toISOString(),
    ])))->toThrow(InvalidArgumentException::class, 'future');
});

test('backdating > 24h needs a reason (-> audit row); within 24h needs none', function () {
    $backdated = CarbonImmutable::now()->subHours(30)->toISOString();

    expect(fn () => new RecordVitalSigns(...recordArgs(['occurredAt' => $backdated])))
        ->toThrow(InvalidArgumentException::class, 'reason');

    $c = new RecordVitalSigns(...recordArgs(['occurredAt' => $backdated, 'reason' => 'from paper obs chart']));
    expect($c->auditEntry()->reason)->toBe('from paper obs chart');

    $recent = new RecordVitalSigns(...recordArgs(['occurredAt' => CarbonImmutable::now()->subHours(2)->toISOString()]));
    expect($recent->auditEntry()->reason)->toBeNull();
});

test('the measurements value object carries the validated set (empty set already rejected by VitalSigns::of)', function () {
    expect(fn () => VitalSigns::of())->toThrow(InvalidArgumentException::class);

    $c = new RecordVitalSigns(...recordArgs(['measurements' => VitalSigns::of(painScore: 4)]));
    expect($c->measurements->painScore)->toBe(4);
});

test('the command is final readonly (IC §5)', function () {
    $r = new ReflectionClass(RecordVitalSigns::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
