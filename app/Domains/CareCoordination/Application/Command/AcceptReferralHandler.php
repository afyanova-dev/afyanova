<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Domains\CareCoordination\Domain\ReferralFeedbackRepository;
use App\Domains\CareCoordination\Domain\ReferralRepository;
use App\Domains\CareCoordination\Domain\Service\ReferralLifecycle;

/**
 * Orchestrates `AcceptReferral` (ADR-151 D-D/D-F). See the command's own
 * docblock for why a `ReferralFeedback` row is created here.
 */
final class AcceptReferralHandler
{
    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly ReferralFeedbackRepository $feedback,
        private readonly ReferralLifecycle $lifecycle,
    ) {}

    public function __invoke(AcceptReferral $command): void
    {
        $referral = $this->referrals->getById($command->referralId);

        $this->lifecycle->assertCanAccept((string) $referral->status);

        $referral->accept();

        $feedback = ReferralFeedback::createExpected(
            id: $command->feedbackId,
            tenantId: (string) $referral->tenant_id,
            facilityId: $referral->receivingFacilityId(),
            referralId: (string) $referral->id,
            createdByUserId: $command->actorId,
        );

        $this->referrals->save($referral);
        $this->feedback->save($feedback);
    }
}
