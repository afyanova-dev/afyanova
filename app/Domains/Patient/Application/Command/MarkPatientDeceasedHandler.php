<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\PatientRepository;

final class MarkPatientDeceasedHandler
{
    public function __construct(private readonly PatientRepository $patients) {}

    public function __invoke(MarkPatientDeceased $command): void
    {
        $patient = $this->patients->getById($command->patientId);
        $patient->markDeceased();
        $this->patients->save($patient);
    }
}
