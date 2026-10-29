<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\ReferralRepository;
use App\Domains\CareCoordination\Domain\Service\ReferralLifecycle;

final class RejectReferralHandler
{
    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly ReferralLifecycle $lifecycle,
    ) {}

    public function __invoke(RejectReferral $command): void
    {
        $referral = $this->referrals->getById($command->referralId);

        $this->lifecycle->assertCanReject((string) $referral->status);

        $referral->reject();

        $this->referrals->save($referral);
    }
}
