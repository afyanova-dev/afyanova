<?php

declare(strict_types=1);

namespace App\Domains\Patient\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * PatientContact aggregate policy (IMPLEMENTATION-CONTRACTS.md §12;
 * 10-patient-domain.md §105; ADR-126). One policy per aggregate root (IC §2.1).
 *
 * UpdatePatientContact is an upsert authorized at class level (there is no
 * subject to load on the create branch); the handler still verifies the
 * contact's patient_id and the patient's tenant. `patient.patients.update`
 * (§105) — no dedicated `patient.contacts.*` key (ADR-126).
 */
final class PatientContactPolicy extends BasePolicy
{
    public function updateContact(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'patient.patients.update');
    }
}
