<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Event;

final readonly class StockReturned
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $itemId,
        public string $locationId,
        public ?string $batchId,
        public string $returnId,
        public string $movementId,
        public string $movementNumber,
        public string $quantity,
        public string $uom,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
