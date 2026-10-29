<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\PatientRepository;

final class ReactivatePatientHandler
{
    public function __construct(private readonly PatientRepository $patients) {}

    public function __invoke(ReactivatePatient $command): void
    {
        $patient = $this->patients->getById($command->patientId);
        $patient->reactivate();
        $this->patients->save($patient);
    }
}
