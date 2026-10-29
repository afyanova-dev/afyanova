<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\ReferralRepository;
use App\Domains\CareCoordination\Domain\Service\ReferralLifecycle;

final class SendReferralHandler
{
    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly ReferralLifecycle $lifecycle,
    ) {}

    public function __invoke(SendReferral $command): void
    {
        $referral = $this->referrals->getById($command->referralId);

        $this->lifecycle->assertCanSend((string) $referral->status);

        $referral->send();

        $this->referrals->save($referral);
    }
}
