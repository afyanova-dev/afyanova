<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

final readonly class FacilityFlowBoardDto
{
    /**
     * @param  list<array{
     *     queue_id: string,
     *     name: string,
     *     code: string,
     *     operating_status: string,
     *     waiting_count: int,
     *     called_count: int,
     *     in_service_count: int,
     *     emergency_count: int,
     *     urgent_count: int,
     *     routine_count: int,
     *     avg_wait_minutes: float,
     *     longest_wait_minutes: float,
     * }>  $queues
     */
    public function __construct(
        public string $facilityId,
        public string $asOf,
        public int $totalWaiting,
        public int $totalInService,
        public array $queues,
    ) {}
}
