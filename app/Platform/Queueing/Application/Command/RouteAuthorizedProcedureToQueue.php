<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * System reaction to Clinical's authorized-procedure event. The event is the
 * authorization provenance; no interactive queue-placement permission applies.
 */
final readonly class RouteAuthorizedProcedureToQueue
{
    public function __construct(
        public string $eventId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $procedureId,
        public string $definitionId,
    ) {
        foreach ([
            'eventId' => $eventId,
            'facilityId' => $facilityId,
            'patientId' => $patientId,
            'encounterId' => $encounterId,
            'procedureId' => $procedureId,
            'definitionId' => $definitionId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RouteAuthorizedProcedureToQueue::\${$name} must be a UUID.");
            }
        }
    }
}
