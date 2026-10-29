<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Search;

use App\Domains\Patient\Domain\Exception\SearchCriteriaTooBroad;

/**
 * The normalized, validated criteria of a `SearchPatients` query
 * (10-patient-domain.md §16, §52, §126–§128; ADR-123, ADR-129). Pure value
 * object — normalization + the ADR-129 specificity floor, no I/O.
 *
 * Every field is nullable: `null` means "not a usable criterion" (an omitted
 * field, a blank string, or whitespace all collapse to `null`). Values are
 * folded the same way the search reader folds the stored columns, so matching
 * is exact-on-normalized-form (no fuzzy matching — ADR-129 D).
 */
final readonly class PatientSearchCriteria
{
    /** ADR-129 C — the PHASE 5 default; §160 tenant-config is a later phase. */
    public const MIN_NAME_FRAGMENT_LENGTH = 3;

    private function __construct(
        public ?string $patientNumber,   // alphanumerics-only, upper
        public ?string $nationalId,      // alphanumerics-only, upper
        public ?string $insuranceId,     // alphanumerics-only, upper
        public ?string $phone,           // digits only
        public ?string $nameFragment,    // folded: upper, non-alnum -> single space, trimmed
        public ?string $dateOfBirth,     // Y-m-d
    ) {}

    public static function fromInput(
        ?string $patientNumber,
        ?string $nationalId,
        ?string $insuranceId,
        ?string $phone,
        ?string $nameFragment,
        ?string $dateOfBirth,
    ): self {
        $number = self::alnum($patientNumber);
        $national = self::alnum($nationalId);
        $insurance = self::alnum($insuranceId);
        $phoneDigits = self::digits($phone);
        $fragment = self::foldName($nameFragment);
        $dob = self::ymdOrNull($dateOfBirth);

        if ($fragment !== null && mb_strlen(str_replace(' ', '', $fragment)) < self::MIN_NAME_FRAGMENT_LENGTH) {
            throw SearchCriteriaTooBroad::nameFragmentTooShort(self::MIN_NAME_FRAGMENT_LENGTH);
        }

        $hasExact = $number !== null || $national !== null || $insurance !== null || $phoneDigits !== null;
        if (! $hasExact && $fragment === null) {
            throw SearchCriteriaTooBroad::noUsableCriterion();
        }

        return new self($number, $national, $insurance, $phoneDigits, $fragment, $dob);
    }

    public function isNameFragmentOnly(): bool
    {
        return $this->nameFragment !== null
            && $this->patientNumber === null
            && $this->nationalId === null
            && $this->insuranceId === null
            && $this->phone === null;
    }

    /**
     * Coded kinds of criteria supplied — for the PHI-free `patient.searched`
     * audit metadata (ADR-129 G). Never the values.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_values(array_filter([
            $this->patientNumber !== null ? 'patient_number' : null,
            $this->nationalId !== null ? 'national_id' : null,
            $this->insuranceId !== null ? 'insurance_id' : null,
            $this->phone !== null ? 'phone' : null,
            $this->nameFragment !== null ? 'name_fragment' : null,
            $this->dateOfBirth !== null ? 'date_of_birth' : null,
        ], static fn (?string $k): bool => $k !== null));
    }

    private static function alnum(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $folded = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        return $folded === '' ? null : $folded;
    }

    private static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $value);

        return $digits === '' ? null : $digits;
    }

    private static function foldName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $folded = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $value));
        $folded = trim((string) preg_replace('/\s+/', ' ', $folded));

        return $folded === '' ? null : $folded;
    }

    private static function ymdOrNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return ($parsed !== false && $parsed->format('Y-m-d') === trim($value)) ? trim($value) : null;
    }
}
