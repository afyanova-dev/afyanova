<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Authorization;

use App\Domains\Laboratory\Application\Command\AcceptLabOrder;
use App\Domains\Laboratory\Application\Command\CancelLabOrder;
use App\Domains\Laboratory\Application\Command\CreateLabOrder;
use App\Domains\Laboratory\Application\Command\RejectLabOrder;
use App\Domains\Laboratory\Application\Command\SubmitLabOrder;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * LabOrder aggregate policy (ADR-157 D-A/D-J).
 *
 * `create` / `submit` / `cancel` are the ordering clinician's actions
 * (`laboratory.orders.create`); `accept` / `reject` are the laboratory's
 * (`laboratory.orders.accept`) — a genuinely different actor / facility scope
 * (§22, the CP.1 / CC.1 multi-actor precedent). `view` gates every
 * `#[ProtectedQuery]` in the module (order reads AND the catalog reads — the
 * CP.1 precedent).
 */
final class LabOrderPolicy extends BasePolicy
{
    /**
     * `CreateLabOrder` carries no `facilityId` — the facility comes from the
     * ENCOUNTER context (ADR-157 D-I), unknown until the handler loads it. This
     * is a coarse, any-facility check only; the handler's own
     * `assertActorScopedToEncounterFacility` (the ADR-133 refinement) enforces
     * the real per-encounter-facility scope.
     */
    public function create(User $user, ?CreateLabOrder $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.orders.create');
    }

    public function submit(User $user, LabOrder $order, ?SubmitLabOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'laboratory.orders.create');
    }

    public function cancel(User $user, LabOrder $order, ?CancelLabOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'laboratory.orders.create');
    }

    public function accept(User $user, LabOrder $order, ?AcceptLabOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'laboratory.orders.accept');
    }

    public function reject(User $user, LabOrder $order, ?RejectLabOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'laboratory.orders.accept');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.access');
    }

    private function hasScope(User $user, LabOrder $order, string $permissionKey): bool
    {
        return $this->sameTenant($user, (string) $order->tenant_id)
            && in_array($permissionKey, $this->scope->effectivePermissions($user, (string) $order->facility_id), true);
    }
}
