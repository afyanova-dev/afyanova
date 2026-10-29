<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Authorization;

use App\Domains\Theatre\Application\Command\AmendOperativeNote;
use App\Domains\Theatre\Application\Command\CreateOperativeNote;
use App\Domains\Theatre\Application\Command\ReviewOperativeNote;
use App\Domains\Theatre\Application\Command\SignOperativeNote;
use App\Domains\Theatre\Application\Command\UpdateOperativeNoteContent;
use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * OperativeNote aggregate policy (ADR-152 D-A/D-I).
 *
 * Two permission keys back four abilities:
 *   theatre.operative_notes.author  → create, author (content edit, review)
 *   theatre.operative_notes.sign    → sign, amend
 *
 * `review` is permission-gated, not qualification-gated (ADR-152 D-F, §80):
 * a single-surgeon facility has no second reviewer, so REVIEWED is optional
 * and any holder of `.author` may record it. Only signing/amending is
 * reserved to `.sign` holders.
 */
final class OperativeNotePolicy extends BasePolicy
{
    /**
     * `CreateOperativeNote` carries no `facilityId` — the facility comes from
     * the SurgicalCase (ADR-152 D-D), unknown until the handler loads it.
     * This is a coarse, any-facility check only; the handler's own
     * `assertActorScopedToCaseFacility` (the `CreateSurgicalCaseHandler`
     * ADR-133 refinement precedent) enforces the real per-case-facility scope.
     */
    public function create(User $user, ?CreateOperativeNote $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.operative_notes.author');
    }

    public function author(User $user, OperativeNote $note, UpdateOperativeNoteContent|ReviewOperativeNote|null $command = null): bool
    {
        return $this->hasNoteScope($user, $note, 'theatre.operative_notes.author');
    }

    public function sign(User $user, OperativeNote $note, SignOperativeNote|AmendOperativeNote|null $command = null): bool
    {
        return $this->hasNoteScope($user, $note, 'theatre.operative_notes.sign');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.access');
    }

    private function hasNoteScope(User $user, OperativeNote $note, string $permissionKey): bool
    {
        return $this->sameTenant($user, (string) $note->tenant_id)
            && in_array(
                $permissionKey,
                $this->scope->effectivePermissions($user, (string) $note->facility_id),
                true,
            );
    }
}
