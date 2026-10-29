<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Port;

interface QueueCallingBroadcasterPort
{
    /**
     * Broadcast ticket call to waiting area displays and rooms via WebSocket.
     */
    public function broadcastCall(
        string $tenantId,
        string $facilityId,
        string $queueId,
        string $ticketNumber,
        ?string $roomId = null,
        string $chimeType = 'STANDARD',
        string $status = 'CALLED',
    ): void;
}
