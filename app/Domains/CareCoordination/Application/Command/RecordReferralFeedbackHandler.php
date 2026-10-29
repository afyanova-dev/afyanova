<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\ReferralFeedbackRepository;
use App\Domains\CareCoordination\Domain\Service\ReferralLifecycle;
use Carbon\CarbonImmutable;

final class RecordReferralFeedbackHandler
{
    public function __construct(
        private readonly ReferralFeedbackRepository $feedback,
        private readonly ReferralLifecycle $lifecycle,
    ) {}

    public function __invoke(RecordReferralFeedback $command): void
    {
        $feedback = $this->feedback->getById($command->feedbackId);

        $this->lifecycle->assertCanRecordContent((string) $feedback->status);

        $feedback->recordContent(
            kind: strtoupper(trim($command->kind)),
            summary: $command->summary,
            outcome: $command->outcome,
            receivedVia: $command->receivedVia !== null ? strtoupper(trim($command->receivedVia)) : null,
            receivedAt: $command->receivedAt !== null ? CarbonImmutable::parse($command->receivedAt) : null,
        );

        $this->feedback->save($feedback);
    }
}
