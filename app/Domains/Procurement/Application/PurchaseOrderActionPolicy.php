<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application;

use App\Domains\Procurement\Contracts\Dto\PurchaseOrderSummary;
use App\Domains\Procurement\Domain\Service\PurchaseOrderLifecycle;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;

/**
 * Which purchase-order actions the CURRENT actor may take on one order, right
 * now.
 *
 * The screen must never guess. Three things decide an action:
 *
 *   1. the lifecycle — you cannot approve a DRAFT (`PurchaseOrderLifecycle`);
 *   2. the permission at the ORDER'S facility (`ScopeChecker`, not the active
 *      context, so a wrong facility can never light up a button);
 *   3. the §24 / §228 different-actor rule — a creator cannot approve and an
 *      approver cannot issue, unless the order is at or below the effective
 *      money limit (`PurchaseOrderSelfApprovalPolicy`).
 *
 * The command handlers re-check all three; this only shapes the UI.
 */
final class PurchaseOrderActionPolicy
{
    public const SUBMIT = 'submit';

    public const APPROVE = 'approve';

    public const ISSUE = 'issue';

    public const CANCEL = 'cancel';

    public const CLOSE_SHORT = 'close-short';

    /**
     * One answer per order per request — the screen asks twice (the action list
     * and the explanation), and each answer reads the limit and the order total.
     *
     * @var array<string, bool>
     */
    private array $selfApprovalCache = [];

    public function __construct(
        private readonly PurchaseOrderLifecycle $lifecycle,
        private readonly PurchaseOrderSelfApprovalPolicy $selfApproval,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<string>
     */
    public function allowedFor(PurchaseOrderSummary $order, User $user): array
    {
        $permissions = $this->scope->effectivePermissions($user, $order->facilityId);
        $actions = [];

        if ($this->lifecycle->canSubmit($order->status)
            && in_array('procurement.orders.create', $permissions, true)) {
            $actions[] = self::SUBMIT;
        }

        if ($this->lifecycle->canApprove($order->status)
            && in_array('procurement.orders.approve', $permissions, true)
            && ($order->createdByUserId !== (string) $user->id || $this->selfApprovalFor($order))) {
            $actions[] = self::APPROVE;
        }

        if ($this->lifecycle->canIssue($order->status)
            && in_array('procurement.orders.issue', $permissions, true)
            && ($order->approvedByUserId !== (string) $user->id || $this->selfApprovalFor($order))) {
            $actions[] = self::ISSUE;
        }

        if ($this->lifecycle->canCloseShort($order->status)
            && in_array('procurement.orders.amend', $permissions, true)) {
            $actions[] = self::CLOSE_SHORT;
        }

        if ($this->lifecycle->canCancel($order->status)
            && in_array('procurement.orders.amend', $permissions, true)) {
            $actions[] = self::CANCEL;
        }

        return $actions;
    }

    /**
     * Whether this order may be carried by one person (the same question the
     * approve / issue handlers ask before refusing a self-approval).
     */
    public function selfApprovalFor(PurchaseOrderSummary $order): bool
    {
        return $this->selfApprovalCache[$order->purchaseOrderId] ??= $this->selfApproval->permitsOrder(
            $order->purchaseOrderId,
            $order->facilityId,
            $order->requisitionId,
        );
    }
}
