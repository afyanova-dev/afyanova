<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Authorization;

use App\Domains\Clinical\Application\Command\AmendClinicalNote;
use App\Domains\Clinical\Application\Command\CancelClinicalNote;
use App\Domains\Clinical\Application\Command\CreateClinicalNote;
use App\Domains\Clinical\Application\Command\SignClinicalNote;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * ClinicalNote aggregate policy (IC §12; 12-clinical-domain.md §87–§90, §159;
 * ADR-133). The exact `EncounterPolicy` shape:
 *
 *   - class-level checks (`create`, `view`) use `permits()` — the key in the
 *     actor's CURRENT facility context;
 *   - per-subject checks (`sign`, `cancel`, `amend`) evaluate the key against
 *     the NOTE's own facility via `ScopeChecker::effectivePermissions`, because
 *     `clin_notes` RLS is tenant-scoped: an actor could load a note at a
 *     facility they are not assigned to (§88, Invariant 24/25 — "facility
 *     selection does not grant clinical-record access").
 *
 * The handlers add a further ADR-133 refinement where the class-level check
 * cannot reach the target facility (`CreateClinicalNote` against the encounter's
 * facility; `GetClinicalNote` against the note's facility).
 *
 * Care-team membership is NOT an authorization input in C.1 (`12` §159 —
 * literal). Break-glass / sensitivity are DEFERRED (ADR-133). Signed-record
 * immutability is enforced in the aggregate, not here.
 */
final class ClinicalNotePolicy extends BasePolicy
{
    /**
     * CreateClinicalNote — class-level (create) check; there is no subject to
     * load yet (IC §5.1). `permits()` resolves `clinical.notes.create` in the
     * actor's current facility context; `CreateClinicalNoteHandler` adds the
     * per-encounter-facility refinement (ADR-133).
     */
    public function create(User $user, ?CreateClinicalNote $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.notes.create');
    }

    /**
     * GetClinicalNote (`#[ProtectedQuery]`, IC §6.2) — class-level check; the
     * QueryBus never loads a subject. Coarse tenant + `clinical.notes.read`. The
     * per-note facility refinement is `GetClinicalNoteHandler`'s (ADR-133).
     */
    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.notes.read');
    }

    /**
     * SignClinicalNote — per-subject check; the bus loaded this note from its
     * (RLS-scoped) repository. `clinical.notes.sign` evaluated against the
     * NOTE's own facility (§88, Invariant 24/25; ADR-133).
     */
    public function sign(User $user, ClinicalNote $note, ?SignClinicalNote $command = null): bool
    {
        return $this->sameTenant($user, (string) $note->tenant_id)
            && in_array(
                'clinical.notes.sign',
                $this->scope->effectivePermissions($user, (string) $note->facility_id),
                true,
            );
    }

    /**
     * CancelClinicalNote — per-subject check. Gated by the **`clinical.notes.amend`**
     * key: there is NO `clinical.notes.cancel` key (ADR-133 — `12` §87 lists
     * create / update / sign / amend, not cancel; cancelling an erroneous draft
     * is a correction). Evaluated against the NOTE's own facility (ADR-133).
     */
    public function cancel(User $user, ClinicalNote $note, ?CancelClinicalNote $command = null): bool
    {
        return $this->sameTenant($user, (string) $note->tenant_id)
            && in_array(
                'clinical.notes.amend',
                $this->scope->effectivePermissions($user, (string) $note->facility_id),
                true,
            );
    }

    /**
     * AmendClinicalNote — per-subject check; appending a correction to a SIGNED
     * note is gated by the privileged **`clinical.notes.amend`** key
     * (the `encounter.encounters.reopen` precedent — distinct from
     * `clinical.notes.create` / `clinical.notes.sign`). Evaluated against the
     * NOTE's own facility (§88, Invariant 24/25; ADR-133).
     */
    public function amend(User $user, ClinicalNote $note, ?AmendClinicalNote $command = null): bool
    {
        return $this->sameTenant($user, (string) $note->tenant_id)
            && in_array(
                'clinical.notes.amend',
                $this->scope->effectivePermissions($user, (string) $note->facility_id),
                true,
            );
    }
}
