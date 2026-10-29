<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class OperationalLocationDto
{
    public function __construct(
        public string $encounterId,
        public string $patientId,
        public string $currentWorkArea,
        public string $currentState,
        public ?string $fromArea = null,
        public ?string $reason = null,
        public ?string $currentQueueEntryId = null,
        public ?string $currentTicketNumber = null,
        public ?string $currentRoomId = null,
        public ?string $currentStaffId = null,
        public ?CarbonImmutable $enteredAt = null,
        public ?CarbonImmutable $asOf = null,
    ) {}
}
