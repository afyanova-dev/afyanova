<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\ReferralRepository;
use App\Domains\CareCoordination\Domain\Service\ReferralLifecycle;

final class ReceiveReferralHandler
{
    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly ReferralLifecycle $lifecycle,
    ) {}

    public function __invoke(ReceiveReferral $command): void
    {
        $referral = $this->referrals->getById($command->referralId);

        $this->lifecycle->assertCanReceive((string) $referral->status);

        $referral->receive();

        $this->referrals->save($referral);
    }
}
