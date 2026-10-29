<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Infrastructure\Adapter;

use App\Platform\Queueing\Contracts\Broadcast\QueueCallingBroadcasted;
use App\Platform\Queueing\Domain\Port\QueueCallingBroadcasterPort;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class ReverbQueueCallingBroadcaster implements QueueCallingBroadcasterPort
{
    /** @var list<array{tenantId: string, facilityId: string, queueId: string, ticketNumber: string, roomId: ?string, chimeType: string, status: string, occurredAt: string}> */
    public static array $broadcasts = [];

    public function broadcastCall(
        string $tenantId,
        string $facilityId,
        string $queueId,
        string $ticketNumber,
        ?string $roomId = null,
        string $chimeType = 'STANDARD',
        string $status = 'CALLED',
    ): void {
        $occurredAt = CarbonImmutable::now()->toIso8601String();

        self::$broadcasts[] = [
            'tenantId' => $tenantId,
            'facilityId' => $facilityId,
            'queueId' => $queueId,
            'ticketNumber' => $ticketNumber,
            'roomId' => $roomId,
            'chimeType' => $chimeType,
            'status' => $status,
            'occurredAt' => $occurredAt,
        ];

        Log::info("Queue Calling Broadcast: Facility [{$facilityId}] Queue [{$queueId}] Ticket [{$ticketNumber}] Room [{$roomId}] Chime [{$chimeType}]");

        try {
            broadcast(new QueueCallingBroadcasted(
                tenantId: $tenantId,
                facilityId: $facilityId,
                queueId: $queueId,
                ticketNumber: $ticketNumber,
                roomId: $roomId,
                chimeType: $chimeType,
                status: $status,
                occurredAt: $occurredAt,
            ));
        } catch (\Throwable $e) {
            Log::warning("WebSocket broadcast failed: {$e->getMessage()}");
        }
    }

    public static function reset(): void
    {
        self::$broadcasts = [];
    }
}
