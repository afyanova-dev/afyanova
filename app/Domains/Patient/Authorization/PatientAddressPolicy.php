<?php

declare(strict_types=1);

namespace App\Domains\Patient\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * PatientAddress aggregate policy (IMPLEMENTATION-CONTRACTS.md §12;
 * 10-patient-domain.md §105; ADR-126). One policy per aggregate root (IC §2.1).
 *
 * UpdatePatientAddress is an upsert authorized at class level; the handler
 * verifies the address's patient_id and the patient's tenant.
 * `patient.patients.update` (§105) — no dedicated `patient.addresses.*` key.
 */
final class PatientAddressPolicy extends BasePolicy
{
    public function updateAddress(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'patient.patients.update');
    }
}
