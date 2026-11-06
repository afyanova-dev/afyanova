<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

final readonly class EncounterQueueEntrySummary
{
    public function __construct(
        public string $patientId,
        public string $status,
    ) {}
}
