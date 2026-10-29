<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Dto;

final readonly class PharmacyOnlyPatientRegistrationOutcome
{
    public const CREATED = 'CREATED';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    /**
     * @param  list<array{patientId: string, patientNumber: string, displayName: string}>  $candidates
     */
    private function __construct(
        public string $status,
        public ?string $patientId,
        public ?string $patientNumber,
        public array $candidates,
    ) {}

    public static function created(string $patientId, string $patientNumber): self
    {
        return new self(self::CREATED, $patientId, $patientNumber, []);
    }

    /**
     * @param  list<array{patientId: string, patientNumber: string, displayName: string}>  $candidates
     */
    public static function reviewRequired(array $candidates): self
    {
        return new self(self::REVIEW_REQUIRED, null, null, $candidates);
    }
}
