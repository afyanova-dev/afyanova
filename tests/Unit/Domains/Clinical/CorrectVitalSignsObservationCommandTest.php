<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectVitalSignsObservation;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.2) unit gate — the CorrectVitalSignsObservation command DTO
 | (IC §3, §5, §18; ADR-138 D7). A correction is a NEW record; there is NO
 | void/cancel command.
 */

function correctArgs(array $overrides = []): array
{
    return array_merge([
        'newObservationId' => (string) Str::uuid7(),
        'supersedesObservationId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'measurements' => VitalSigns::of(systolicBp: 128, diastolicBp: 82),
        'reason' => 'BP mis-transcribed',
    ], $overrides);
}

test('it is a per-subject command — subjectId is the observation being corrected, ability "correct"', function () {
    $args = correctArgs();
    $c = new CorrectVitalSignsObservation(...$args);

    expect($c)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($c)->toBeInstanceOf(Auditable::class)
        ->and($c->policyAbility())->toBe('correct')
        ->and($c->subjectId())->toBe($args['supersedesObservationId']);
});

test('auditEntry() -> clinical.vitals.corrected, targets the NEW observation, reason NOT on the audit (ADR-138 C)', function () {
    $args = correctArgs();
    $entry = (new CorrectVitalSignsObservation(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.vitals.corrected')
        ->and($entry->targetType)->toBe('vital_signs_observation')
        ->and($entry->targetId)->toBe($args['newObservationId'])
        ->and($entry->reason)->toBeNull()          // the correction rationale is clinical data on the row
        ->and($entry->metadata)->toBe([]);
});

test('reason is mandatory', function () {
    expect(fn () => new CorrectVitalSignsObservation(...correctArgs(['reason' => '  '])))
        ->toThrow(InvalidArgumentException::class);
});

test('an observation cannot supersede itself', function () {
    $id = (string) Str::uuid7();
    expect(fn () => new CorrectVitalSignsObservation(...correctArgs([
        'newObservationId' => $id, 'supersedesObservationId' => $id,
    ])))->toThrow(InvalidArgumentException::class);
});

test('it rejects malformed uuids and a future occurred_at', function () {
    expect(fn () => new CorrectVitalSignsObservation(...correctArgs(['newObservationId' => 'nope'])))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new CorrectVitalSignsObservation(...correctArgs([
        'occurredAt' => CarbonImmutable::now()->addDay()->toISOString(),
    ])))->toThrow(InvalidArgumentException::class, 'future');
});

test('there is no separate cancel/void command class in the Clinical vitals command set', function () {
    $commands = array_map(
        static fn (string $f): string => basename($f, '.php'),
        glob(base_path('app/Domains/Clinical/Application/Command/*Vital*.php')) ?: [],
    );

    expect($commands)->toEqualCanonicalizing([
        'RecordVitalSigns', 'RecordVitalSignsHandler', 'RecordVitalSignsResult',
        'CorrectVitalSignsObservation', 'CorrectVitalSignsObservationHandler',
    ]);
    foreach ($commands as $c) {
        expect($c)->not->toContain('Cancel')->and($c)->not->toContain('Void');
    }
});

test('the command is final readonly (IC §5)', function () {
    $r = new ReflectionClass(CorrectVitalSignsObservation::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
