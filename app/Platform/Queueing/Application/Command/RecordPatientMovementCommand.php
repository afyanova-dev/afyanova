<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

final readonly class RecordPatientMovementCommand
{
    public function __construct(
        public string $encounterId,
        public string $patientId,
        public string $toArea,
        public ?string $fromArea = null,
        public string $status = 'ARRIVED',
        public ?string $queueEntryId = null,
        public ?string $actorId = null,
        public ?string $reason = null,
    ) {}
}
