<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;

final class ClinicianRoleDefinition
{
    public const KEY = FacilityUserRoleCatalog::CLINICIAN;

    public const PERMISSIONS = [
        'clinical.access',
        'clinical.notes.read',
        'clinical.notes.create',
        'clinical.notes.sign',
        'clinical.vitals.read',
        'clinical.triage.read',
        'clinical.diagnoses.read',
        'clinical.diagnoses.record',
        'clinical.plans.read',
        'clinical.plans.record',
        'clinical.consultations.read',
        'clinical.consultations.create',
        'clinical.procedures.request',
        'encounter.encounters.read',
        'encounter.encounters.update',
        'encounter.encounters.manage_disposition',
        'encounter.encounters.complete',
        'sch.access',
        'patient.patients.read',
        'queue.access',
        'queue.ticket.view',
        'queue.ticket.call',
        'queue.ticket.service',
        'queue.ticket.transfer',
    ];
}
