<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

final readonly class RegisterPatientResult
{
    public function __construct(
        public string $patientId,
        public string $patientNumber,
    ) {}
}
