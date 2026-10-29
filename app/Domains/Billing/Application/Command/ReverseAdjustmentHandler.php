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
use Illuminate\Support\Str;

/**
 * Orchestrates `ReverseAdjustment` (ADR-191 D7-B). `getByIdForUpdate` → no-op
 * on REVERSED → `assertCanReverse` (APPLIED) → the reverser must not be the
 * requester (`SelfApprovalNotPermitted`) → `reverse` → save → compensating
 * OPPOSITE-direction `ADJUSTMENT` (`source_type 'ADJUSTMENT_REVERSAL'`).
 */
final class ReverseAdjustmentHandler
{
    public function __construct(
        private readonly AdjustmentRepository $adjustments,
        private readonly LedgerPoster $ledger,
        private readonly FinancialControlLifecycle $lifecycle,
    ) {}

    public function __invoke(ReverseAdjustment $command): void
    {
        $adjustment = $this->adjustments->getByIdForUpdate($command->adjustmentId);

        if ($adjustment->isReversed()) {
            return;
        }

        $this->lifecycle->assertCanReverse((string) $adjustment->status);

        if ((string) $adjustment->requested_by_user_id === $command->actorId) {
            throw SelfApprovalNotPermitted::forAdjustment($command->adjustmentId);
        }

        $reversalId = (string) Str::uuid7();

        $adjustment->reverse($reversalId, $command->actorId, $command->reasonCode);
        $this->adjustments->save($adjustment);

        $this->ledger->post(
            financialAccountId: (string) $adjustment->financial_account_id,
            facilityId: (string) $adjustment->facility_id,
            entryType: LedgerEntryType::ADJUSTMENT,
            direction: $adjustment->reversalDirection(),
            amount: Money::of((string) $adjustment->amount),
            currency: (string) $adjustment->currency,
            sourceType: LedgerSourceType::ADJUSTMENT_REVERSAL,
            sourceId: $reversalId,
        );
    }
}
