<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Infrastructure\Listener;

use App\Domains\Clinical\Contracts\Event\TriageAssessmentRecorded;
use App\Platform\Queueing\Application\Command\UpdateTicketPriorityCommand;
use App\Shared\Bus\CommandBus;

final class OnTriageAssessmentRecorded
{
    public function __construct(private readonly CommandBus $bus) {}

    public function __invoke(TriageAssessmentRecorded $event): void
    {
        $this->bus->dispatch(new UpdateTicketPriorityCommand(
            encounterId: $event->encounterId,
            priority: $event->triagePriority,
            provenance: "TriageAssessment:{$event->triageAssessmentId}",
        ));
    }
}
