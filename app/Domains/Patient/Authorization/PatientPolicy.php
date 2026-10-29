<?php

declare(strict_types=1);

namespace App\Domains\Patient\Authorization;

use App\Domains\Patient\Application\Command\RegisterPatient;
use App\Domains\Patient\Domain\Model\Patient;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Patient aggregate policy (IMPLEMENTATION-CONTRACTS.md §12;
 * 10-patient-domain.md §105).
 */
final class PatientPolicy extends BasePolicy
{
    /**
     * RegisterPatient — class-level (create) check; there is no subject to
     * load yet (IC §5.1). The bus passes the command as the second argument
     * (AuthorizesAgainst): a duplicate-override re-submission additionally
     * requires `patient.patients.override_duplicate`, checked here so the
     * override cannot be forced past the authoritative bus check (ADR-117).
     */
    public function register(User $user, ?RegisterPatient $command = null): bool
    {
        if (! $this->context->hasTenant() || (string) $user->tenant_id !== $this->context->tenantId()) {
            return false;
        }

        $requiredPermission = $command?->source === 'PHARMACY_EXTERNAL_PRESCRIPTION'
            ? 'patient.patients.create_pharmacy_only'
            : 'patient.patients.create';

        if (! $this->permits($user, $requiredPermission)) {
            return false;
        }

        if ($command?->overrideDuplicate === true && ! $this->permits($user, 'patient.patients.override_duplicate')) {
            return false;
        }

        return true;
    }

    /**
     * GetPatientSummary (#[ProtectedQuery], IC §6.2) — class-level check; the
     * QueryBus never loads a subject instance (App\Shared\Bus\Middleware\
     * AuthorizeQuery has no per-row loading path).
     */
    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'patient.patients.read');
    }

    /**
     * UpdatePatient — per-subject check; the bus loaded this patient from its
     * (RLS-scoped) repository (IC §5.1). ADR-127 keeps this on the base
     * `patient.patients.update` key — the `reason`-mandatory rule for sensitive
     * fields is enforced in the command, not with a distinct permission.
     */
    public function update(User $user, Patient $patient): bool
    {
        return $this->sameTenant($user, (string) $patient->tenant_id)
            && $this->permits($user, 'patient.patients.update');
    }

    /**
     * SearchPatients (#[ProtectedQuery], IC §6.2; ADR-129 F) — class-level
     * check; the QueryBus never loads a subject instance. Search is a read, so
     * it maps to the EXISTING `patient.patients.read` key (§105) — no separate
     * `patient.patients.search` key. The anti-enumeration protection is the
     * layered controls (minimum-criteria 422, per-result facility flag + audit,
     * pagination, HTTP rate-limit), not permission granularity.
     */
    public function search(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'patient.patients.read');
    }

    /**
     * MarkPatientInactive — per-subject check; the bus loaded this patient
     * from its (RLS-scoped) repository (IC §5.1).
     */
    public function deactivate(User $user, Patient $patient): bool
    {
        return $this->sameTenant($user, (string) $patient->tenant_id)
            && $this->permits($user, 'patient.patients.deactivate');
    }

    /**
     * ReactivatePatient — same lifecycle authority as deactivation.
     */
    public function reactivate(User $user, Patient $patient): bool
    {
        return $this->sameTenant($user, (string) $patient->tenant_id)
            && $this->permits($user, 'patient.patients.deactivate');
    }

    /**
     * MarkPatientDeceased — a distinctly sensitive administrative operation
     * (10-patient-domain.md §45), gated by its own key.
     */
    public function markDeceased(User $user, Patient $patient): bool
    {
        return $this->sameTenant($user, (string) $patient->tenant_id)
            && $this->permits($user, 'patient.patients.mark_deceased');
    }

    /**
     * MergePatients AND RequestUnmerge — per-subject check; the bus loaded the
     * non-surviving patient from its (RLS-scoped) repository (IC §5.1). Both
     * commands map to `policyAbility() = 'merge'` and share
     * `patient.patients.merge` (ADR-128 M-2). The surviving patient's tenant is
     * re-checked in the handler.
     */
    public function merge(User $user, Patient $patient): bool
    {
        return $this->sameTenant($user, (string) $patient->tenant_id)
            && $this->permits($user, 'patient.patients.merge');
    }
}
