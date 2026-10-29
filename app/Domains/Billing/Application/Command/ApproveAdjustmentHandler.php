<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\AdjustmentRepository;
use App\Domains\Billing\Domain\Exception\SelfApprovalNotPermitted;
use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\Service\FinancialControlLifecycle;
use App\Domains\Billing\Domain\ValueObject\LedgerEntryType;
use App\Domains\Billing\Domain\ValueObject\LedgerSourceType;

/**
 * Orchestrates `ApproveAdjustment` (ADR-191 G-3). `getByIdForUpdate` →
 * `assertCanApprove` (REQUESTED) → the approver must not be the requester
 * (`SelfApprovalNotPermitted`) → `apply` → save → post the signed ledger
 * `ADJUSTMENT` (direction per the type).
 */
final class ApproveAdjustmentHandler
{
    public function __construct(
        private readonly AdjustmentRepository $adjustments,
        private readonly LedgerPoster $ledger,
        private readonly FinancialControlLifecycle $lifecycle,
    ) {}

    public function __invoke(ApproveAdjustment $command): void
    {
        $adjustment = $this->adjustments->getByIdForUpdate($command->adjustmentId);

        $this->lifecycle->assertCanApprove((string) $adjustment->status);

        if ((string) $adjustment->requested_by_user_id === $command->actorId) {
            throw SelfApprovalNotPermitted::forAdjustment($command->adjustmentId);
        }

        $adjustment->apply($command->actorId);
        $this->adjustments->save($adjustment);

        $this->ledger->post(
            financialAccountId: (string) $adjustment->financial_account_id,
            facilityId: (string) $adjustment->facility_id,
            entryType: LedgerEntryType::ADJUSTMENT,
            direction: (string) $adjustment->direction,
            amount: Money::of((string) $adjustment->amount),
            currency: (string) $adjustment->currency,
            sourceType: LedgerSourceType::ADJUSTMENT,
            sourceId: $command->adjustmentId,
        );
    }
}
