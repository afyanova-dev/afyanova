<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Infrastructure\Listener;

use App\Domains\Clinical\Contracts\Event\ProcedureAuthorized;
use App\Platform\Queueing\Application\Command\RouteAuthorizedProcedureToQueue;
use App\Shared\Bus\CommandBus;

final class OnProcedureAuthorized
{
    public function __construct(private readonly CommandBus $bus) {}

    public function __invoke(ProcedureAuthorized $event): void
    {
        $this->bus->dispatch(new RouteAuthorizedProcedureToQueue(
            eventId: $event->eventId,
            facilityId: $event->facilityId,
            patientId: $event->patientId,
            encounterId: $event->encounterId,
            procedureId: $event->procedureId,
            definitionId: $event->definitionId,
        ), $event->eventId);
    }
}
