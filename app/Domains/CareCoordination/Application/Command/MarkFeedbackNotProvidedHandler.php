<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\ReferralFeedbackRepository;
use App\Domains\CareCoordination\Domain\Service\ReferralLifecycle;

final class MarkFeedbackNotProvidedHandler
{
    public function __construct(
        private readonly ReferralFeedbackRepository $feedback,
        private readonly ReferralLifecycle $lifecycle,
    ) {}

    public function __invoke(MarkFeedbackNotProvided $command): void
    {
        $feedback = $this->feedback->getById($command->feedbackId);

        $this->lifecycle->assertCanMarkFeedbackNotProvided((string) $feedback->status);

        $feedback->markNotProvided();

        $this->feedback->save($feedback);
    }
}
