<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application;

use App\Domains\Procurement\Contracts\Dto\PurchaseRequisitionSummary;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\Service\RequisitionLifecycle;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;

/**
 * Which requisition actions the CURRENT actor may take on one request, right
 * now.
 *
 * Same three authorities as a purchase order (`PurchaseOrderActionPolicy`):
 * the lifecycle, the permission at the request's own facility, and — the part
 * that is NOT relaxed by any money limit — the §24 / §228 different-actor rule:
 * whoever raises a request cannot approve or reject it. Small facilities still
 * need two people for the request itself; the one-person convenience starts at
 * the purchase order.
 *
 * `order` is the hand-off to the Purchase orders screen (allowed once the
 * request is APPROVED and the actor may create orders) — it opens the order
 * panel prefilled from this request, which is also what carries this request's
 * department into the approval-limit rule.
 *
 * The command handlers re-check all of it; this only shapes the UI.
 */
final class PurchaseRequisitionActionPolicy
{
    public const SUBMIT = 'submit';

    public const EDIT = 'edit';

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const CANCEL = 'cancel';

    public const ORDER = 'order';

    public function __construct(
        private readonly RequisitionLifecycle $lifecycle,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<string>
     */
    public function allowedFor(PurchaseRequisitionSummary $requisition, User $user): array
    {
        $permissions = $this->scope->effectivePermissions(
            $user,
            $requisition->facilityId,
            $requisition->requestingDepartmentRef,
        );
        $isCreator = $requisition->createdByUserId === (string) $user->id;
        $mayRaise = in_array('procurement.requisitions.create', $permissions, true);
        $mayReview = in_array('procurement.requisitions.approve', $permissions, true);
        $actions = [];

        if ($this->lifecycle->canSubmit($requisition->status) && $mayRaise) {
            $actions[] = self::SUBMIT;
        }

        $editableLines = $requisition->lines !== []
            && count(array_filter(
                $requisition->lines,
                static fn ($line): bool => $line->lineType === 'STOCK' && $line->itemRef !== null,
            )) === count($requisition->lines);

        if ($this->lifecycle->canEditDraft($requisition->status)
            && $mayRaise
            && $isCreator
            && $editableLines) {
            $actions[] = self::EDIT;
        }

        // Deliberately never a self-approval — no limit, no exception.
        if ($this->lifecycle->canApprove($requisition->status) && $mayReview && ! $isCreator) {
            $actions[] = self::APPROVE;
        }

        if ($this->lifecycle->canReject($requisition->status) && $mayReview && ! $isCreator) {
            $actions[] = self::REJECT;
        }

        if ($this->lifecycle->canCancel($requisition->status) && $mayRaise) {
            $actions[] = self::CANCEL;
        }

        $facilityPermissions = $this->scope->effectivePermissions($user, $requisition->facilityId);
        if ($this->canBeOrdered($requisition) && in_array('procurement.orders.create', $facilityPermissions, true)) {
            $actions[] = self::ORDER;
        }

        return $actions;
    }

    /** An approved request may become a purchase order (never a draft or a rejected one). */
    public function canBeOrdered(PurchaseRequisitionSummary $requisition): bool
    {
        return $requisition->status === PurchaseRequisition::STATUS_APPROVED
            && ! $requisition->hasActivePurchaseOrder;
    }
}
