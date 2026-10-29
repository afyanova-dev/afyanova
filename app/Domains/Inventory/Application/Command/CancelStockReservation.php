<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cancel an ACTIVE stock reservation with a mandatory reason
 * (18-inventory-and-warehousing-domain.md §113, Inv 18; ADR-180 D-E). ACTIVE →
 * CANCELLED; the held `reserved_qty` returns to available. `reason` is
 * ROW-ONLY (the ADR-136 deny-list) — carried on the dedicated `AuditEntry`
 * `reason:` column, never in the event.
 */
final readonly class CancelStockReservation implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $reservationId,
        public string $actorId,
        public string $reason,
    ) {
        if (! Str::isUuid($reservationId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CancelStockReservation ids must be UUIDs (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('CancelStockReservation::$reason must not be blank.');
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
            action: 'inventory.stock_reservation_cancelled',
            actorId: $this->actorId,
            targetType: 'stock_reservation',
            targetId: $this->reservationId,
            reason: trim($this->reason),
        );
    }
}
