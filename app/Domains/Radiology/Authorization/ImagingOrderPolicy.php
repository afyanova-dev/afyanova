<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Authorization;

use App\Domains\Radiology\Application\Command\AcceptImagingOrder;
use App\Domains\Radiology\Application\Command\CancelImagingOrder;
use App\Domains\Radiology\Application\Command\CreateImagingOrder;
use App\Domains\Radiology\Application\Command\RejectImagingOrder;
use App\Domains\Radiology\Application\Command\SubmitImagingOrder;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * ImagingOrder aggregate policy (ADR-165 D-A/D-K).
 *
 * `create` / `submit` / `cancel` are the ordering clinician's actions
 * (`radiology.orders.create`); `accept` / `reject` are Radiology's
 * (`radiology.orders.accept`) — a genuinely different actor / facility scope
 * (§22, the CP.1 / CC.1 / Lab.1 multi-actor precedent). `view` gates every
 * `#[ProtectedQuery]` in the module (order reads AND the catalog reads — the
 * CP.1 / Lab.1 precedent).
 */
final class ImagingOrderPolicy extends BasePolicy
{
    /**
     * `CreateImagingOrder` carries no `facilityId` — the facility comes from
     * the ENCOUNTER context (ADR-165 D-J), unknown until the handler loads it.
     * This is a coarse, any-facility check only; the handler's own
     * `assertActorScopedToEncounterFacility` (the ADR-133 refinement) enforces
     * the real per-encounter-facility scope.
     */
    public function create(User $user, ?CreateImagingOrder $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'radiology.orders.create');
    }

    public function submit(User $user, ImagingOrder $order, ?SubmitImagingOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'radiology.orders.create');
    }

    public function cancel(User $user, ImagingOrder $order, ?CancelImagingOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'radiology.orders.create');
    }

    public function accept(User $user, ImagingOrder $order, ?AcceptImagingOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'radiology.orders.accept');
    }

    public function reject(User $user, ImagingOrder $order, ?RejectImagingOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'radiology.orders.accept');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'radiology.access');
    }

    private function hasScope(User $user, ImagingOrder $order, string $permissionKey): bool
    {
        return $this->sameTenant($user, (string) $order->tenant_id)
            && in_array($permissionKey, $this->scope->effectivePermissions($user, (string) $order->facility_id), true);
    }
}
