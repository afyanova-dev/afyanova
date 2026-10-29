<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Exception\SelfApprovalNotPermitted;
use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\Service\FinancialControlLifecycle;
use App\Domains\Billing\Domain\ValueObject\LedgerEntryType;
use App\Domains\Billing\Domain\ValueObject\LedgerSourceType;
use App\Domains\Billing\Domain\WriteOffRepository;
use Illuminate\Support\Str;

/**
 * Orchestrates `ReverseWriteOff` (ADR-191 D7-B). `getByIdForUpdate` → no-op on
 * REVERSED → `assertCanReverse` (APPLIED) → the reverser must not be the
 * original requester (`SelfApprovalNotPermitted`) → `reverse` (records
 * `WriteOffReversed`) → save → compensating `WRITE_OFF` / `DEBIT`
 * (`source_type 'WRITE_OFF_REVERSAL'`).
 */
final class ReverseWriteOffHandler
{
    public function __construct(
        private readonly WriteOffRepository $writeOffs,
        private readonly LedgerPoster $ledger,
        private readonly FinancialControlLifecycle $lifecycle,
    ) {}

    public function __invoke(ReverseWriteOff $command): void
    {
        $writeOff = $this->writeOffs->getByIdForUpdate($command->writeOffId);

        if ($writeOff->isReversed()) {
            return;
        }

        $this->lifecycle->assertCanReverse((string) $writeOff->status);

        if ((string) $writeOff->requested_by_user_id === $command->actorId) {
            throw SelfApprovalNotPermitted::forWriteOff($command->writeOffId);
        }

        $reversalId = (string) Str::uuid7();

        $writeOff->reverse($reversalId, $command->actorId, $command->reasonCode);
        $this->writeOffs->save($writeOff);

        $this->ledger->post(
            financialAccountId: (string) $writeOff->financial_account_id,
            facilityId: (string) $writeOff->facility_id,
            entryType: LedgerEntryType::WRITE_OFF,
            direction: 'DEBIT',
            amount: Money::of((string) $writeOff->amount),
            currency: (string) $writeOff->currency,
            sourceType: LedgerSourceType::WRITE_OFF_REVERSAL,
            sourceId: $reversalId,
        );
    }
}
