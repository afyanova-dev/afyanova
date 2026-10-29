<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application;

use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Money;
use App\Domains\Procurement\Domain\PurchaseOrderReader;
use App\Domains\Procurement\Domain\PurchaseRequisitionReader;
use App\Shared\Domain\NotFound;

/**
 * The single-actor approval policy for purchase orders.
 *
 * The domain assumes a purchasing department: creator, approver and issuer are
 * three different people (19-procurement-domain.md §24 / §228). A small private
 * hospital cannot staff that, so a money limit stands in for the missing
 * people — an order at or below the EFFECTIVE limit may be approved AND issued
 * by the same person.
 *
 * The effective limit is per facility and optionally per department (see
 * {@see ApprovalLimitResolver}) — a single deployment-wide number cannot
 * describe a dispensary and a referral hospital at once. The department comes
 * from the linked requisition, because a purchase order itself carries none:
 * a direct order is judged at the facility limit.
 *
 * Above the limit, or when the limit is `0`, the guard is unchanged: the second
 * actor is still required.
 *
 * `permits()` takes the aggregate (the command handlers); `permitsOrder()` takes
 * plain ids so the screen can ask the same question about a read DTO without
 * loading the aggregate.
 */
final class PurchaseOrderSelfApprovalPolicy
{
    public function __construct(
        private readonly PurchaseOrderReader $orders,
        private readonly PurchaseRequisitionReader $requisitions,
        private readonly ApprovalLimitResolver $limits,
    ) {}

    /**
     * May `$actorId` — already the creator (at approve time) or the approver (at
     * issue time) — carry this order forward alone?
     */
    public function permits(PurchaseOrder $order): bool
    {
        return $this->permitsOrder(
            (string) $order->id,
            (string) $order->facility_id,
            $order->requisition_id !== null ? (string) $order->requisition_id : null,
        );
    }

    /**
     * The same question for a read model: the order id (for its total), the
     * facility (for the limit) and the optional requisition (for the
     * department).
     */
    public function permitsOrder(string $purchaseOrderId, string $facilityId, ?string $requisitionId): bool
    {
        $limit = $this->limits->resolve($facilityId, $this->departmentOf($requisitionId));

        // `0` (or unset) disables the relaxation — and a PO with no lines must
        // never slip through, hence the strict positivity guard before the
        // `>=` comparison.
        if (! Money::isPositive($limit)) {
            return false;
        }

        return Money::gte($limit, $this->orders->orderTotal($purchaseOrderId));
    }

    /**
     * The department the order belongs to, via its requisition — or null for a
     * direct order. An unreadable requisition falls back to the facility limit.
     */
    private function departmentOf(?string $requisitionId): ?string
    {
        if ($requisitionId === null) {
            return null;
        }

        try {
            return $this->requisitions->summary($requisitionId)->requestingDepartmentRef;
        } catch (NotFound) {
            return null;
        }
    }
}
