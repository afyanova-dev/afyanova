<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\FollowUpRepository;
use App\Domains\CareCoordination\Domain\Model\FollowUp;
use App\Domains\CareCoordination\Domain\Service\FollowUpLifecycle;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Shared\Domain\NotFound;

/**
 * Orchestrates `RecordFollowUpOutcome` (ADR-154 D-C/D-I). For a KEPT outcome
 * the `resultingEncounterId` is checked to exist via
 * `EncounterApi::encounterExists` (the unprotected existence check — the
 * follow-up is not encounter-state-gated).
 */
final class RecordFollowUpOutcomeHandler
{
    public function __construct(
        private readonly FollowUpRepository $followUps,
        private readonly FollowUpLifecycle $lifecycle,
        private readonly EncounterApi $encounters,
    ) {}

    public function __invoke(RecordFollowUpOutcome $command): void
    {
        $followUp = $this->followUps->getById($command->followUpId);

        $this->lifecycle->assertCanRecordOutcome((string) $followUp->status);
        $this->lifecycle->assertOutcomeComplete($command->outcome, $command->resultingEncounterId);

        if ($command->outcome === FollowUp::STATUS_KEPT
            && ! $this->encounters->encounterExists((string) $command->resultingEncounterId)) {
            throw NotFound::aggregate(FollowUp::class, $command->followUpId);
        }

        $followUp->recordOutcome($command->outcome, $command->resultingEncounterId);

        $this->followUps->save($followUp);
    }
}
