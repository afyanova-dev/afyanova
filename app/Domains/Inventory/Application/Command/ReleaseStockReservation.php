<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Release an ACTIVE stock reservation — the planned consumer no longer needs it
 * (18-inventory-and-warehousing-domain.md §113, Inv 18; ADR-180 D-E). ACTIVE →
 * RELEASED; the held `reserved_qty` returns to available.
 */
final readonly class ReleaseStockReservation implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $reservationId,
        public string $actorId,
    ) {
        if (! Str::isUuid($reservationId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReleaseStockReservation ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->reservationId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.stock_reservation_released',
            actorId: $this->actorId,
            targetType: 'stock_reservation',
            targetId: $this->reservationId,
        );
    }
}
