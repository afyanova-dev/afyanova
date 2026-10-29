<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain;

use App\Domains\Patient\Domain\Model\PatientStickyNote;
use App\Shared\Domain\Repository;

interface PatientStickyNoteRepository extends Repository
{
    public function getById(string $id): PatientStickyNote;

    public function getByIdForUpdate(string $id): PatientStickyNote;

    public function findActiveForPatient(string $patientId): ?PatientStickyNote;

    public function findActiveForPatientForUpdate(string $patientId): ?PatientStickyNote;
}
