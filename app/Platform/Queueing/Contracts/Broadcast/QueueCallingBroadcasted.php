<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\Broadcast;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final readonly class QueueCallingBroadcasted implements ShouldBroadcastNow
{
    public function __construct(
        public string $tenantId,
        public string $facilityId,
        public string $queueId,
        public string $ticketNumber,
        public ?string $roomId,
        public string $chimeType,
        public string $status,
        public string $occurredAt,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("facility.{$this->facilityId}.calling-board"),
            new PrivateChannel("facility.{$this->facilityId}.queue.{$this->queueId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'QueueCallingBroadcasted';
    }

    /**
     * Payload contains ZERO PHI per AGENTS.md §38 and IC §22.3.
     *
     * @return array<string, string|null>
     */
    public function broadcastWith(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'facility_id' => $this->facilityId,
            'queue_id' => $this->queueId,
            'ticket_number' => $this->ticketNumber,
            'room_id' => $this->roomId,
            'chime_type' => $this->chimeType,
            'status' => $this->status,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
