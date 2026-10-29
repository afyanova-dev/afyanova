<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Authorization;

use App\Domains\Procurement\Application\Command\AmendPurchaseOrder;
use App\Domains\Procurement\Application\Command\ApprovePurchaseOrder;
use App\Domains\Procurement\Application\Command\CancelPurchaseOrder;
use App\Domains\Procurement\Application\Command\ClosePurchaseOrderShort;
use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Application\Command\IssuePurchaseOrder;
use App\Domains\Procurement\Application\Command\SubmitPurchaseOrder;
use App\Domains\Procurement\Application\Query\GetGoodsReceiptPurchaseOrder;
use App\Domains\Procurement\Application\Query\GetItemPriceReferences;
use App\Domains\Procurement\Application\Query\GetOpenPurchaseOrders;
use App\Domains\Procurement\Application\Query\GetPurchaseOrder;
use App\Domains\Procurement\Application\Query\SearchPurchaseOrders;
use App\Domains\Procurement\Application\Query\SearchReceivablePurchaseOrderLines;
use App\Domains\Procurement\Application\Query\SearchReceivablePurchaseOrders;
use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;

/**
 * PurchaseOrder aggregate policy (ADR-171 D-H / D-M).
 *
 * `create` is a coarse class-level check (the handler's ADR-133 refinement
 * enforces the real per-facility scope). Per-subject keys, all at the PO's own
 * facility (§228 different-actor segregation, F2): `submit` →
 * `procurement.orders.create`; `approve` → `procurement.orders.approve`;
 * `issue` → `procurement.orders.issue`; `amend` / `cancel` →
 * `procurement.orders.amend`. `view` gates every `#[ProtectedQuery]`.
 */
final class PurchaseOrderPolicy extends BasePolicy
{
    public function __construct(
        TenantContext $context,
        ScopeChecker $scope,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {
        parent::__construct($context, $scope);
    }

    public function create(User $user, ?CreatePurchaseOrder $command = null): bool
    {
        return $this->coarse($user, 'procurement.orders.create');
    }

    public function submit(User $user, PurchaseOrder $order, ?SubmitPurchaseOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'procurement.orders.create');
    }

    public function approve(User $user, PurchaseOrder $order, ?ApprovePurchaseOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'procurement.orders.approve');
    }

    public function issue(User $user, PurchaseOrder $order, ?IssuePurchaseOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'procurement.orders.issue');
    }

    public function amend(User $user, PurchaseOrder $order, ?AmendPurchaseOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'procurement.orders.amend');
    }

    public function cancel(User $user, PurchaseOrder $order, ?CancelPurchaseOrder $command = null): bool
    {
        return $this->hasScope($user, $order, 'procurement.orders.amend');
    }

    public function closeShort(User $user, PurchaseOrder $order, ?ClosePurchaseOrderShort $command = null): bool
    {
        return $this->hasScope($user, $order, 'procurement.orders.amend');
    }

    public function view(
        User $user,
        GetGoodsReceiptPurchaseOrder|GetItemPriceReferences|GetOpenPurchaseOrders|GetPurchaseOrder|SearchReceivablePurchaseOrders|SearchReceivablePurchaseOrderLines|null $query = null,
    ): bool {
        if ($this->coarse($user, 'procurement.access')) {
            return true;
        }

        $queryFacilityId = match (true) {
            $query instanceof GetOpenPurchaseOrders,
            $query instanceof SearchReceivablePurchaseOrders,
            $query instanceof SearchReceivablePurchaseOrderLines,
            $query instanceof GetGoodsReceiptPurchaseOrder,
            $query instanceof GetItemPriceReferences => $query->facilityId,
            default => null,
        };
        $facilityId = $queryFacilityId ?? $this->context->facilityId();

        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $facilityId !== null
            && $facilityId !== ''
            && ($queryFacilityId === null || $queryFacilityId === $facilityId)
            && $this->departmentScope->canAccessWorkspace($user, $facilityId);
    }

    public function list(User $user, SearchPurchaseOrders $query): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->departmentScope->canAccessWorkspace($user, $query->facilityId);
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }

    private function hasScope(User $user, PurchaseOrder $order, string $key): bool
    {
        return $this->sameTenant($user, (string) $order->tenant_id)
            && in_array($key, $this->scope->effectivePermissions($user, (string) $order->facility_id), true);
    }
}
