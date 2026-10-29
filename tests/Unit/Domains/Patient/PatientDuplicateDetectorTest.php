<?php

declare(strict_types=1);

use App\Domains\Patient\Domain\DuplicateCandidateReader;
use App\Domains\Patient\Domain\Enum\DuplicateOutcome;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Service\DuplicateCandidate;
use App\Domains\Patient\Domain\Service\DuplicateSignals;
use App\Domains\Patient\Domain\Service\PatientDuplicateDetector;
use Illuminate\Support\Str;

/*
 | ADR-117 — the PHASE 5 default matching policy: score the canonical signal
 | set, map the top score to BLOCK / REVIEW / ALLOW. Pure — a fake reader
 | supplies the candidate set.
 */

/**
 * @param  list<DuplicateCandidate>  $candidates
 */
function detectorWith(array $candidates): PatientDuplicateDetector
{
    return new PatientDuplicateDetector(new class($candidates) implements DuplicateCandidateReader
    {
        /** @param list<DuplicateCandidate> $candidates */
        public function __construct(private array $candidates) {}

        public function candidatesFor(DuplicateSignals $signals): array
        {
            return $this->candidates;
        }
    });
}

function dupSignals(
    string $given = 'Asha',
    string $family = 'Mkwawa',
    ?string $dob = '1990-05-01',
    Sex $sex = Sex::Female,
    ?string $phone = null,
    ?string $nationalId = null,
): DuplicateSignals {
    return DuplicateSignals::fromRegistration($given, $family, $dob, $sex, $phone, $nationalId, null);
}

function dupCandidate(
    string $given = 'Asha',
    string $family = 'Mkwawa',
    ?string $dob = '1990-05-01',
    string $sex = 'FEMALE',
    ?string $phone = null,
    bool $verifiedIdMatch = false,
): DuplicateCandidate {
    return new DuplicateCandidate(
        patientId: (string) Str::uuid7(),
        patientNumber: 'PAT-00000001',
        displayName: "{$given} {$family}",
        dateOfBirth: $dob,
        normalizedName: DuplicateSignals::fold("{$given} {$family}"),
        normalizedFamilyName: DuplicateSignals::fold($family),
        normalizedPhone: $phone,
        sex: $sex,
        hasVerifiedIdentifierMatch: $verifiedIdMatch,
    );
}

test('no candidates -> ALLOW', function () {
    expect(detectorWith([])->assess(dupSignals())->outcome)->toBe(DuplicateOutcome::Allow);
});

test('exact full name + DOB + sex -> BLOCK (strong combination, ADR-117)', function () {
    $assessment = detectorWith([dupCandidate()])->assess(dupSignals());

    expect($assessment->outcome)->toBe(DuplicateOutcome::Block)
        ->and($assessment->matched)->toHaveCount(1)
        ->and($assessment->confidenceBand)->toBe('high');
});

test('a VERIFIED identifier match is a BLOCK on its own, even with different demographics', function () {
    $assessment = detectorWith([
        dupCandidate(given: 'Totally', family: 'Different', dob: '1955-01-01', sex: 'MALE', verifiedIdMatch: true),
    ])->assess(dupSignals(nationalId: '19900501-01234'));

    expect($assessment->outcome)->toBe(DuplicateOutcome::Block);
});

test('same full name + DOB but sex unknown -> REVIEW (75, not enough for BLOCK)', function () {
    $assessment = detectorWith([dupCandidate()])->assess(dupSignals(sex: Sex::Unknown));

    expect($assessment->outcome)->toBe(DuplicateOutcome::Review)
        ->and($assessment->confidenceBand)->toBe('medium');
});

test('same family name + phone -> REVIEW (55)', function () {
    $assessment = detectorWith([
        dupCandidate(given: 'Different', family: 'Mkwawa', dob: '1971-01-01', sex: 'MALE', phone: '255700111222'),
    ])->assess(dupSignals(given: 'Asha', family: 'Mkwawa', dob: '1990-05-01', sex: Sex::Female, phone: '+255 700 111 222'));

    expect($assessment->outcome)->toBe(DuplicateOutcome::Review);
});

test('only the family name in common -> ALLOW (20)', function () {
    $assessment = detectorWith([
        dupCandidate(given: 'Baraka', family: 'Mkwawa', dob: '1965-03-03', sex: 'MALE'),
    ])->assess(dupSignals());

    expect($assessment->outcome)->toBe(DuplicateOutcome::Allow);
});

test('matched candidates are ordered strongest-first and exclude sub-threshold rows', function () {
    $weak = dupCandidate(given: 'Baraka', family: 'Mkwawa', dob: '1965-03-03', sex: 'MALE');   // 20 -> excluded
    $strong = dupCandidate();                                                                   // 85

    $assessment = detectorWith([$weak, $strong])->assess(dupSignals());

    expect($assessment->matched)->toHaveCount(1)
        ->and($assessment->matched[0]->patientId)->toBe($strong->patientId);
});
