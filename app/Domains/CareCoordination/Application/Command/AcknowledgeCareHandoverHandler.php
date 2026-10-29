<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\ReferralRepository;
use App\Domains\CareCoordination\Domain\Service\ReferralLifecycle;

final class AcknowledgeCareHandoverHandler
{
    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly ReferralLifecycle $lifecycle,
    ) {}

    public function __invoke(AcknowledgeCareHandover $command): void
    {
        $referral = $this->referrals->getById($command->referralId);

        $this->lifecycle->assertCanAcknowledgeHandover((string) $referral->status, (bool) $referral->implies_handover);

        $referral->acknowledgeHandover();

        $this->referrals->save($referral);
    }
}
