<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Withdraw a REQUESTED or HELD reservation (24-bed-and-ward-management-domain.md §9;
 * ADR-149 D-B/D-F). If HELD, the bed reverts to AVAILABLE.
 */
final readonly class CancelBedReservation implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $reservationId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($reservationId)) {
            throw new InvalidArgumentException('CancelBedReservation::$reservationId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CancelBedReservation::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'cancel';
    }

    public function subjectId(): string
    {
        return $this->reservationId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.reservation_cancelled',
            actorId: $this->actorId,
            targetType: 'bed_reservation',
            targetId: $this->reservationId,
            metadata: [],
        );
    }
}
