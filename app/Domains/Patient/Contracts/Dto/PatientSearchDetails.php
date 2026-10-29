<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Dto;

final readonly class PatientSearchDetails
{
    public function __construct(
        public string $patientId,
        public ?string $dateOfBirth,
        public ?string $dobPrecision,
        public bool $hasPhoto,
        public bool $registeredAtFacility,
    ) {}
}
