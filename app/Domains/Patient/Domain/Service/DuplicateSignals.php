<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Service;

use App\Domains\Patient\Domain\Enum\Sex;
use Illuminate\Support\Str;

/**
 * The normalized identity signals a registration presents to duplicate
 * detection (ADR-117 SIGNALS). Pure value object — normalization only, no I/O.
 * Every string is folded the same way the stored candidate columns are, so
 * comparisons are exact-match on normalized forms.
 */
final readonly class DuplicateSignals
{
    /**
     * @param  non-empty-string  $normalizedName  given + family, folded
     * @param  non-empty-string  $normalizedFamilyName
     */
    public function __construct(
        public string $normalizedName,
        public string $normalizedFamilyName,
        public ?string $dateOfBirth,
        public Sex $sex,
        public ?string $normalizedPhone,
        public ?string $normalizedNationalId,
        public ?string $normalizedInsuranceId,
    ) {}

    public static function fromRegistration(
        string $givenName,
        string $familyName,
        ?string $dateOfBirth,
        Sex $sex,
        ?string $primaryPhone,
        ?string $nationalId,
        ?string $insuranceMemberNumber,
    ): self {
        return new self(
            normalizedName: self::fold("{$givenName} {$familyName}"),
            normalizedFamilyName: self::fold($familyName),
            dateOfBirth: $dateOfBirth,
            sex: $sex,
            normalizedPhone: self::digitsOrNull($primaryPhone),
            normalizedNationalId: self::identifierOrNull($nationalId),
            normalizedInsuranceId: self::identifierOrNull($insuranceMemberNumber),
        );
    }

    /** @return non-empty-string */
    public static function fold(string $value): string
    {
        $folded = Str::upper((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $value));
        $folded = trim((string) preg_replace('/\s+/', ' ', $folded));

        return $folded === '' ? '?' : $folded;
    }

    private static function digitsOrNull(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $phone);

        return $digits === '' ? null : $digits;
    }

    /**
     * Case-folded, alphanumerics only — the same rule the PatientIdentifier
     * aggregate applies to normalized_value, kept inline here so this value
     * object carries no aggregate dependency.
     */
    private static function identifierOrNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}
