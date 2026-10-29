<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Authorization;

final class ClinicalRolePermissions
{
    /**
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        return [
            'receptionist' => [
                'clinical.documentation_status.read',
            ],
            'nurse' => [
                'clinical.access',
                'clinical.notes.read',
                'clinical.notes.create',
                'clinical.triage.record',
                'clinical.triage.read',
                'clinical.vitals.read',
                'clinical.vitals.record',
                'clinical.procedures.read',
                'clinical.procedures.perform',
                'clinical.procedures.complete',
            ],
            'doctor' => [
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
            ],
        ];
    }
}
