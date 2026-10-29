<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Authorization;

use App\Domains\Procurement\Application\Command\ApprovePurchaseRequisition;
use App\Domains\Procurement\Application\Command\CancelPurchaseRequisition;
use App\Domains\Procurement\Application\Command\CreatePurchaseRequisition;
use App\Domains\Procurement\Application\Command\RejectPurchaseRequisition;
use App\Domains\Procurement\Application\Command\SubmitPurchaseRequisition;
use App\Domains\Procurement\Application\Command\UpdateDraftPurchaseRequisition;
use App\Domains\Procurement\Application\Query\GetPurchaseRequisition;
use App\Domains\Procurement\Application\Query\GetRequisitionApprovalQueue;
use App\Domains\Procurement\Application\Query\SearchPurchaseRequisitions;
use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;

/**
 * PurchaseRequisition aggregate policy (ADR-171 D-F / D-M).
 *
 * `create` is a coarse, any-facility class-level check (the handler's ADR-133
 * refinement enforces the real per-facility scope — the `ImagingOrderPolicy::create`
 * precedent). `submit` / `cancel` gate `procurement.requisitions.create`;
 * `approve` gates `procurement.requisitions.approve` (§24 different actor —
 * backs both `Approve` and `Reject`). Per-subject checks are at the
 * requisition's own facility (F9). `view` gates every `#[ProtectedQuery]`.
 */
final class PurchaseRequisitionPolicy extends BasePolicy
{
    public function __construct(
        TenantContext $context,
        ScopeChecker $scope,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {
        parent::__construct($context, $scope);
    }

    public function create(User $user, ?CreatePurchaseRequisition $command = null): bool
    {
        if ($command === null) {
            return $this->coarse($user, 'procurement.requisitions.create');
        }

        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && in_array(
                'procurement.requisitions.create',
                $this->scope->effectivePermissions(
                    $user,
                    $command->facilityId,
                    $command->requestingDepartmentRef,
                ),
                true,
            );
    }

    public function submit(User $user, PurchaseRequisition $requisition, ?SubmitPurchaseRequisition $command = null): bool
    {
        return $this->hasScope($user, $requisition, 'procurement.requisitions.create');
    }

    public function cancel(User $user, PurchaseRequisition $requisition, ?CancelPurchaseRequisition $command = null): bool
    {
        return $this->hasScope($user, $requisition, 'procurement.requisitions.create');
    }

    public function update(
        User $user,
        PurchaseRequisition $requisition,
        ?UpdateDraftPurchaseRequisition $command = null,
    ): bool {
        return $command !== null
            && $this->hasScope($user, $requisition, 'procurement.requisitions.create')
            && (string) $requisition->created_by_user_id === (string) $user->id
            && $requisition->status === PurchaseRequisition::STATUS_DRAFT;
    }

    public function approve(
        User $user,
        PurchaseRequisition $requisition,
        ApprovePurchaseRequisition|RejectPurchaseRequisition|null $command = null,
    ): bool {
        return $this->hasScope($user, $requisition, 'procurement.requisitions.approve');
    }

    public function view(
        User $user,
        SearchPurchaseRequisitions|GetPurchaseRequisition|GetRequisitionApprovalQueue|null $query = null,
    ): bool {
        if ($query instanceof SearchPurchaseRequisitions || $query instanceof GetPurchaseRequisition) {
            return $this->departmentScope->canAccessWorkspace($user, $query->facilityId);
        }

        return $this->coarse($user, 'procurement.access');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }

    private function hasScope(User $user, PurchaseRequisition $requisition, string $key): bool
    {
        return $this->sameTenant($user, (string) $requisition->tenant_id)
            && in_array(
                $key,
                $this->scope->effectivePermissions(
                    $user,
                    (string) $requisition->facility_id,
                    $requisition->requesting_department_ref !== null
                        ? (string) $requisition->requesting_department_ref
                        : null,
                ),
                true,
            );
    }
}
