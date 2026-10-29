<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\PatientRepository;

final class MarkPatientInactiveHandler
{
    public function __construct(private readonly PatientRepository $patients) {}

    public function __invoke(MarkPatientInactive $command): void
    {
        $patient = $this->patients->getById($command->patientId); // RLS-scoped; NotFound if missing / cross-tenant
        $patient->markInactive();                                 // ADR-116 transition guard; records PatientDeactivated
        $this->patients->save($patient);                          // versioned UPDATE + outbox (IC §7.2, §9.1)
    }
}
