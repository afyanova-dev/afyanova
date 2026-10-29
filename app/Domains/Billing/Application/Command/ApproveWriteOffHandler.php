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

/**
 * Orchestrates `ApproveWriteOff` (ADR-191 G-3). `getByIdForUpdate` →
 * `assertCanApprove` (REQUESTED) → the approver must not be the requester
 * (`SelfApprovalNotPermitted` — the Procurement §228 precedent) → `apply`
 * (records `WriteOffApplied`) → save → post the ledger `WRITE_OFF` / `CREDIT`.
 */
final class ApproveWriteOffHandler
{
    public function __construct(
        private readonly WriteOffRepository $writeOffs,
        private readonly LedgerPoster $ledger,
        private readonly FinancialControlLifecycle $lifecycle,
    ) {}

    public function __invoke(ApproveWriteOff $command): void
    {
        $writeOff = $this->writeOffs->getByIdForUpdate($command->writeOffId);

        $this->lifecycle->assertCanApprove((string) $writeOff->status);

        if ((string) $writeOff->requested_by_user_id === $command->actorId) {
            throw SelfApprovalNotPermitted::forWriteOff($command->writeOffId);
        }

        $writeOff->apply($command->actorId);
        $this->writeOffs->save($writeOff);

        $this->ledger->post(
            financialAccountId: (string) $writeOff->financial_account_id,
            facilityId: (string) $writeOff->facility_id,
            entryType: LedgerEntryType::WRITE_OFF,
            direction: 'CREDIT',
            amount: Money::of((string) $writeOff->amount),
            currency: (string) $writeOff->currency,
            sourceType: LedgerSourceType::WRITE_OFF,
            sourceId: $command->writeOffId,
        );
    }
}
