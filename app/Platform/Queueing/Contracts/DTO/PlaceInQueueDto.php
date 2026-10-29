<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

final readonly class PlaceInQueueDto
{
    public function __construct(
        public string $encounterId,
        public string $patientId,
        public string $queueId,
        public string $priority = 'ROUTINE',
        public ?string $sourceOrderRef = null,
        public ?string $orderType = null,
    ) {}
}
