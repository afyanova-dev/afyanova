<?php

declare(strict_types=1);

namespace App\Domains\Patient\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * PatientRegistration aggregate policy (IMPLEMENTATION-CONTRACTS.md §12;
 * ADR-114). One policy per aggregate root (IC §2.1) — kept separate from
 * PatientPolicy because PatientRegistration is its own aggregate.
 */
final class PatientRegistrationPolicy extends BasePolicy
{
    /**
     * RegisterPatientAtFacility — class-level (create) check; there is no
     * subject to load yet (IC §5.1).
     */
    public function registerAtFacility(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'patient.registrations.create');
    }
}
