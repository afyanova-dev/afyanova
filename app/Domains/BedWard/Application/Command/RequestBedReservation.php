<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Request a hold on a specific bed (24-bed-and-ward-management-domain.md §9;
 * ADR-149 D-B/D-F).
 *
 * Creates a REQUESTED reservation regardless of the target bed's current
 * status — a caller may request a hold on a bed that is still OCCUPIED (a
 * call-ahead for an expected vacancy). `HoldBedReservation` is the later
 * confirmation once the bed is actually AVAILABLE.
 */
final readonly class RequestBedReservation implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $reservationId,
        public string $bedId,
        public ?string $encounterId,
        public ?string $patientId,
        public string $reason,
        public CarbonImmutable $holdUntil,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($reservationId)) {
            throw new InvalidArgumentException('RequestBedReservation::$reservationId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($bedId)) {
            throw new InvalidArgumentException('RequestBedReservation::$bedId must be a UUID (IC §3).');
        }

        if ($encounterId !== null && ! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('RequestBedReservation::$encounterId must be a UUID (IC §3).');
        }

        if ($patientId !== null && ! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('RequestBedReservation::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RequestBedReservation::$actorId must be a UUID (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('RequestBedReservation::$reason must be a non-blank string.');
        }
    }

    public function policyAbility(): string
    {
        return 'request';
    }

    public function subjectId(): string
    {
        return $this->bedId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.reservation_requested',
            actorId: $this->actorId,
            targetType: 'bed_reservation',
            targetId: $this->reservationId,
            metadata: [
                'bed_id' => $this->bedId,
                'reason' => $this->reason,
            ],
        );
    }
}
