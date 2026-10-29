<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Service;

/**
 * One existing patient the duplicate-candidate reader surfaced as worth
 * scoring against an incoming registration (ADR-117). Carries the folded
 * comparison fields plus the minimum display data the BLOCK / REVIEW response
 * is allowed to echo back (ADR-117 PRIVACY: id, number, display name, DOB).
 */
final readonly class DuplicateCandidate
{
    public function __construct(
        public string $patientId,
        public string $patientNumber,
        public string $displayName,
        public ?string $dateOfBirth,
        public string $normalizedName,
        public string $normalizedFamilyName,
        public ?string $normalizedPhone,
        public string $sex,
        public bool $hasVerifiedIdentifierMatch,
    ) {}
}
