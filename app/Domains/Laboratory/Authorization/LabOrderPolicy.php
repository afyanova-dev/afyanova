<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Authorization;

use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Laboratory\Application\Command\AcceptLabOrder;
use App\Domains\Laboratory\Application\Command\CancelLabOrder;
use App\Domains\Laboratory\Application\Command\CreateLabOrder;
use App\Domains\Laboratory\Application\Command\RejectLabOrder;
use App\Domains\Laboratory\Application\Command\StartLabExecution;
use App\Domains\Laboratory\Application\Command\SubmitLabOrder;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;

/**
 * LabOrder aggregate policy (ADR-157 D-A/D-J).
 *
 * `create` / `submit` / `cancel` are the ordering clinician's actions
 * (`laboratory.orders.create`); `accept` / `reject` are the laboratory's
 * (`laboratory.orders.accept`) — a genuinely different actor / facility scope
 * (§22, the CP.1 / CC.1 multi-actor precedent). `view` gates order reads;
 * `viewCatalog` gates catalog reads.
 */
final class LabOrderPolicy extends BasePolicy
{
    public function __construct(
        TenantContext $context,
        ScopeChecker $scope,
        private readonly IdentityApi $identity,
    ) {
        parent::__construct($context, $scope);
    }

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

    public function execute(User $user, LabOrder $order, ?StartLabExecution $command = null): bool
    {
        return $this->hasScope($user, $order, 'laboratory.results.enter');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.access');
    }

    public function viewCatalog(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && (
                $this->permits($user, 'laboratory.catalog.read')
                || $this->permits($user, 'laboratory.access')
                || $this->permits($user, 'laboratory.orders.create')
                || (
                    $this->permits($user, 'laboratory.catalog.manage')
                    && $this->identity->isActiveLaboratoryTechnician($this->context->tenantId(), (string) $user->getKey())
                )
            );
    }

    public function manageCatalog(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.catalog.manage')
            && $this->identity->isActiveLaboratoryTechnician($this->context->tenantId(), (string) $user->getKey());
    }

    public function manageInstruments(User $user): bool
    {
        $facilityId = $this->context->facilityId();

        return $this->context->hasTenant()
            && $facilityId !== null
            && $facilityId !== ''
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'laboratory.catalog.manage')
            && $this->identity->isActiveLaboratoryTechnician($this->context->tenantId(), (string) $user->getKey())
            && $this->scope->hasFacilityPermissionInContext(
                $user,
                $this->context,
                $facilityId,
                'laboratory.access',
            );
    }

    private function hasScope(User $user, LabOrder $order, string $permissionKey): bool
    {
        return $this->sameTenant($user, (string) $order->tenant_id)
            && $this->scope->hasFacilityPermissionInContext(
                $user,
                $this->context,
                (string) $order->facility_id,
                $permissionKey,
            );
    }
}
