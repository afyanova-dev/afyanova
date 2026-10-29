<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\ReferralRepository;

/**
 * Orchestrates `ExpireReferral` (ADR-151 D-F/D-J). Mirrors
 * `ExpireBedReservationHandler`'s defensive shape: a system sweep may race
 * against a human action, so an already-resolved referral is a silent no-op,
 * not a thrown `IllegalReferralTransition` — `ReferralLifecycle::
 * assertCanExpire` is deliberately NOT used here.
 */
final class ExpireReferralHandler
{
    public function __construct(
        private readonly ReferralRepository $referrals,
    ) {}

    public function __invoke(ExpireReferral $command): void
    {
        $referral = $this->referrals->getById($command->referralId);

        if ($referral->status !== Referral::STATUS_SENT && $referral->status !== Referral::STATUS_RECEIVED) {
            return; // already resolved (accepted/rejected/cancelled/completed/expired) — nothing to do
        }

        $referral->expire();

        $this->referrals->save($referral);
    }
}
