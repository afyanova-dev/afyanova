<?php

declare(strict_types=1);

namespace App\Domains\Patient\Authorization;

use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * PatientIdentifier aggregate policy (IMPLEMENTATION-CONTRACTS.md §12;
 * 10-patient-domain.md §40, §105; ADR-117, ADR-122). One policy per aggregate
 * root (IC §2.1).
 *
 * `patient.identifiers.manage` gates writing identifiers (add + verify).
 * `patient.identifiers.view_sensitive` (ADR-122) gates UNMASKED display and is
 * checked in the Presentation layer, not here — PHASE 5 registers the key
 * (permissions.php); enforcement is PHASE 16 (ADR-124).
 */
final class PatientIdentifierPolicy extends BasePolicy
{
    /**
     * AddPatientIdentifier — class-level (create) check; there is no subject to
     * load yet (IC §5.1).
     */
    public function add(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'patient.identifiers.manage');
    }

    /**
     * VerifyPatientIdentifier — per-subject check; the bus loaded this
     * identifier from its (RLS-scoped) repository (IC §5.1).
     */
    public function verify(User $user, PatientIdentifier $identifier): bool
    {
        return $this->sameTenant($user, (string) $identifier->tenant_id)
            && $this->permits($user, 'patient.identifiers.manage');
    }
}
