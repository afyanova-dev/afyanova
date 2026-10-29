<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class PatientMovementDto
{
    public function __construct(
        public string $id,
        public string $encounterId,
        public string $patientId,
        public ?string $fromArea,
        public string $toArea,
        public string $status,
        public ?string $queueEntryId,
        public ?string $actorId,
        public ?string $reason,
        public CarbonImmutable $occurredAt,
    ) {}
}
