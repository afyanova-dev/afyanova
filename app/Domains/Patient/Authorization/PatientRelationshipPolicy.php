<?php

declare(strict_types=1);

namespace App\Domains\Patient\Authorization;

use App\Domains\Patient\Domain\Model\PatientRelationship;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * PatientRelationship aggregate policy (IMPLEMENTATION-CONTRACTS.md §12;
 * 10-patient-domain.md §105; ADR-126). One policy per aggregate root (IC §2.1).
 *
 * Relationship writes use the established Patient update permission
 * `patient.patients.update` (§105) — ADR-126 introduces no dedicated
 * `patient.relationships.*` key.
 */
final class PatientRelationshipPolicy extends BasePolicy
{
    /**
     * RecordPatientRelationship — class-level (create) check; there is no
     * subject to load yet (IC §5.1).
     */
    public function recordRelationship(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'patient.patients.update');
    }

    /**
     * RemovePatientRelationship — per-subject check; the bus loaded this
     * relationship from its (RLS-scoped) repository (IC §5.1).
     */
    public function removeRelationship(User $user, PatientRelationship $relationship): bool
    {
        return $this->sameTenant($user, (string) $relationship->tenant_id)
            && $this->permits($user, 'patient.patients.update');
    }
}
