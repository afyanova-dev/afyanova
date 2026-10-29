<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class CallingBoardDto
{
    /**
     * @param  list<CallingBoardItemDto>  $calledTickets
     * @param  list<CallingBoardItemDto>  $waitingTickets
     */
    public function __construct(
        public string $facilityId,
        public ?string $queueId,
        public array $calledTickets,
        public array $waitingTickets,
        public CarbonImmutable $asOf,
        public string $operatingStatus = 'ACTIVE',
        public bool $isPaused = false,
        public ?string $pauseReason = null,
    ) {}
}
