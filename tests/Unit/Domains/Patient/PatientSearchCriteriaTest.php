<?php

declare(strict_types=1);

use App\Domains\Patient\Domain\Exception\SearchCriteriaTooBroad;
use App\Domains\Patient\Domain\Search\PatientSearchCriteria;

/*
 | PHASE 5 (increment A.7) unit gate — the SearchPatients criteria value object:
 | normalization + the ADR-129 C specificity floor (§16, §52, §128; ADR-129).
 */

/**
 * @param  array<string, string|null>  $input
 */
function criteria(array $input = []): PatientSearchCriteria
{
    return PatientSearchCriteria::fromInput(
        $input['patientNumber'] ?? null,
        $input['nationalId'] ?? null,
        $input['insuranceId'] ?? null,
        $input['phone'] ?? null,
        $input['nameFragment'] ?? null,
        $input['dateOfBirth'] ?? null,
    );
}

test('a patient number is folded to alphanumerics-only uppercase', function () {
    expect(criteria(['patientNumber' => ' pat-000123 '])->patientNumber)->toBe('PAT000123');
});

test('a national / insurance identifier is normalized the same way PatientIdentifier normalizes', function () {
    $c = criteria(['nationalId' => '19900101-12345-678', 'insuranceId' => 'nhif/ 88 ']);
    expect($c->nationalId)->toBe('1990010112345678')
        ->and($c->insuranceId)->toBe('NHIF88');
});

test('a phone is reduced to digits', function () {
    expect(criteria(['phone' => '+255 (700) 123-456'])->phone)->toBe('255700123456');
});

test('a name fragment is folded — uppercased, punctuation and runs of space collapse to one space, trimmed', function () {
    expect(criteria(['nameFragment' => "  o'brien-  smith "])->nameFragment)->toBe('O BRIEN SMITH');
});

test('blank / whitespace-only fields collapse to null (not a usable criterion)', function () {
    expect(fn () => criteria(['patientNumber' => '   ', 'nameFragment' => '']))
        ->toThrow(SearchCriteriaTooBroad::class);
});

test('a criteria-free query is rejected with SearchCriteriaTooBroad (HTTP 422)', function () {
    try {
        criteria([]);
        $this->fail('expected SearchCriteriaTooBroad');
    } catch (SearchCriteriaTooBroad $e) {
        expect($e->httpStatus())->toBe(422)
            ->and($e->errorCode())->toBe('search_criteria_too_broad');
    }
});

test('a name fragment shorter than 3 (ignoring spaces) is rejected', function () {
    expect(fn () => criteria(['nameFragment' => 'Jo']))->toThrow(SearchCriteriaTooBroad::class);
    expect(fn () => criteria(['nameFragment' => 'a b']))->toThrow(SearchCriteriaTooBroad::class); // "ab" -> 2
});

test('a 3-character name fragment is accepted', function () {
    expect(criteria(['nameFragment' => 'Mkw'])->nameFragment)->toBe('MKW');
});

test('a date of birth on its own is rejected — it is a refinement, never a standalone criterion', function () {
    expect(fn () => criteria(['dateOfBirth' => '1990-05-01']))->toThrow(SearchCriteriaTooBroad::class);
});

test('a date of birth is accepted only alongside a real criterion, and a malformed one is silently dropped', function () {
    expect(criteria(['nameFragment' => 'Mkwawa', 'dateOfBirth' => '1990-05-01'])->dateOfBirth)->toBe('1990-05-01');
    expect(criteria(['nameFragment' => 'Mkwawa', 'dateOfBirth' => '01/05/1990'])->dateOfBirth)->toBeNull();
    expect(criteria(['nameFragment' => 'Mkwawa', 'dateOfBirth' => 'garbage'])->dateOfBirth)->toBeNull();
});

test('the minimum name-fragment length is the documented hardcoded default of 3', function () {
    expect(PatientSearchCriteria::MIN_NAME_FRAGMENT_LENGTH)->toBe(3);
});

test('isNameFragmentOnly() is true only when the sole criterion is a name fragment', function () {
    expect(criteria(['nameFragment' => 'Mkwawa'])->isNameFragmentOnly())->toBeTrue()
        ->and(criteria(['nameFragment' => 'Mkwawa', 'dateOfBirth' => '1990-05-01'])->isNameFragmentOnly())->toBeTrue()
        ->and(criteria(['nameFragment' => 'Mkwawa', 'phone' => '0700111222'])->isNameFragmentOnly())->toBeFalse()
        ->and(criteria(['patientNumber' => 'PAT-1'])->isNameFragmentOnly())->toBeFalse();
});

test('kinds() reports only the coded kinds supplied — never the values', function () {
    expect(criteria(['patientNumber' => 'PAT-1', 'dateOfBirth' => '1990-05-01'])->kinds())
        ->toEqualCanonicalizing(['patient_number', 'date_of_birth']);
    expect(criteria(['nameFragment' => 'Mkwawa'])->kinds())->toBe(['name_fragment']);
});
