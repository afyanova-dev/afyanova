<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\Event;

final readonly class PatientMovementRecorded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $movementId,
        public string $encounterId,
        public string $patientId,
        public ?string $fromArea,
        public string $toArea,
        public string $status,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
