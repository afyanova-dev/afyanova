<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordAllergy;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.5) unit gate — the RecordAllergy command DTO (IC §3, §5, §18;
 | ADR-132, ADR-141 D-A … D-V).
 */

function recordAllergyArgs(array $overrides = []): array
{
    return array_merge([
        'allergyId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(),
        'encounterId' => (string) Str::uuid7(),
        'category' => 'DRUG',
        'substance' => AllergySubstance::of('Penicillin', '764146007', 'SNOMED-CT'),
        'reactionDescription' => 'Urticaria',
        'severity' => 'MODERATE',
    ], $overrides);
}

test('it is a class-level create command — subjectId null, ability "record", Auditable + AuthorizesAgainst', function () {
    $c = new RecordAllergy(...recordAllergyArgs());

    expect($c)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($c)->toBeInstanceOf(Auditable::class)
        ->and($c->policyAbility())->toBe('record')
        ->and($c->subjectId())->toBeNull();
});

test('auditEntry() -> clinical.allergies.recorded; no substance / reaction / severity in metadata', function () {
    $args = recordAllergyArgs(['onsetDate' => '2025-01-01']);
    $entry = (new RecordAllergy(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.allergies.recorded')
        ->and($entry->actorId)->toBe($args['actorId'])
        ->and($entry->targetType)->toBe('allergy')
        ->and($entry->targetId)->toBe($args['allergyId'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it rejects malformed uuids (IC §3)', function (string $field) {
    expect(fn () => new RecordAllergy(...recordAllergyArgs([$field => 'invalid-uuid'])))->toThrow(InvalidArgumentException::class);
})->with(['allergyId', 'actorId', 'patientId', 'encounterId']);

test('it rejects a blank category (ADR-141 D-F)', function () {
    expect(fn () => new RecordAllergy(...recordAllergyArgs(['category' => '   '])))->toThrow(InvalidArgumentException::class);
});

test('it rejects REFUTED verificationStatus at origination (ADR-141 D-K)', function () {
    expect(fn () => new RecordAllergy(...recordAllergyArgs(['verificationStatus' => AllergyVerificationStatus::REFUTED])))
        ->toThrow(InvalidArgumentException::class);
});

test('it accepts SUSPECTED and CONFIRMED verificationStatus', function (string $status) {
    $c = new RecordAllergy(...recordAllergyArgs(['verificationStatus' => $status]));
    expect($c->verificationStatus)->toBe($status);
})->with(['SUSPECTED', 'CONFIRMED']);

test('it accepts CLINICIAN and NURSING authoredContext and rejects others (ADR-141 D-I)', function () {
    $c1 = new RecordAllergy(...recordAllergyArgs(['authoredContext' => Allergy::AUTHORED_CONTEXT_CLINICIAN]));
    expect($c1->authoredContext)->toBe('CLINICIAN');

    $c2 = new RecordAllergy(...recordAllergyArgs(['authoredContext' => Allergy::AUTHORED_CONTEXT_NURSING]));
    expect($c2->authoredContext)->toBe('NURSING');

    expect(fn () => new RecordAllergy(...recordAllergyArgs(['authoredContext' => 'ADMIN'])))
        ->toThrow(InvalidArgumentException::class);
});

test('it rejects a future onset_date but accepts any past date without a reason (ADR-141 D-H)', function () {
    expect(fn () => new RecordAllergy(...recordAllergyArgs(['onsetDate' => CarbonImmutable::now()->addDay()->toDateString()])))
        ->toThrow(InvalidArgumentException::class, 'future');

    expect((new RecordAllergy(...recordAllergyArgs(['onsetDate' => '1995-05-10'])))->onsetDate)->toBe('1995-05-10');
});

test('it rejects a future occurred_at', function () {
    expect(fn () => new RecordAllergy(...recordAllergyArgs(['occurredAt' => CarbonImmutable::now()->addDay()->toISOString()])))
        ->toThrow(InvalidArgumentException::class, 'future');
});

test('backdating occurred_at > 24h needs a reason (-> audit row); within 24h needs none', function () {
    $backdated = CarbonImmutable::now()->subHours(30)->toISOString();

    expect(fn () => new RecordAllergy(...recordAllergyArgs(['occurredAt' => $backdated])))
        ->toThrow(InvalidArgumentException::class, 'reason');

    $c = new RecordAllergy(...recordAllergyArgs(['occurredAt' => $backdated, 'reason' => 'documented from intake chart']));
    expect($c->auditEntry()->reason)->toBe('documented from intake chart');

    $recent = new RecordAllergy(...recordAllergyArgs(['occurredAt' => CarbonImmutable::now()->subHours(2)->toISOString()]));
    expect($recent->auditEntry()->reason)->toBeNull();
});

test('the command is final readonly (IC §5)', function () {
    $r = new ReflectionClass(RecordAllergy::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
