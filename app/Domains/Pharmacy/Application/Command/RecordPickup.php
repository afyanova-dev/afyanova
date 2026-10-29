<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\ValueObject\PickupCode;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Record a medication pickup — {READY_FOR_PICKUP | NOT_COLLECTED} → PICKED_UP
 * (16-pharmacy-domain.md §29–§31; ADR-186 D-D).
 *
 * A pharmacist attestation — NO automated identity check (§31 "the pharmacy MAY
 * require"; the Pharm.2a "no automated safety" precedent). `recipientName` (a
 * representative's name) and `verificationNote` (what was checked) are ROW-ONLY
 * (ADR-136 — never in an event / audit metadata).
 *
 * Carries `pharmacyOrderId` + `dispensingId` (the ADR-185 "authorize against the
 * order, cross-check" pattern → PickupMismatch). Authorizes against the order
 * (`pickup` — `pharmacy.pickup.record` at the order's facility).
 */
final readonly class RecordPickup implements Auditable, AuthorizesAgainst
{
    public string $recipientType;

    public function __construct(
        public string $actorId,
        public string $pharmacyOrderId,
        public string $dispensingId,
        string $recipientType,
        public ?string $recipientName = null,
        public ?string $verificationNote = null,
        public ?string $occurredAt = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['actorId' => $actorId, 'pharmacyOrderId' => $pharmacyOrderId, 'dispensingId' => $dispensingId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordPickup::\${$name} must be a UUID (IC §3).");
            }
        }

        $this->recipientType = PickupCode::assertRecipient($recipientType);

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordPickup::$occurredAt must be an ISO-8601 datetime or null.');
            }

            if ($occurred->greaterThan(CarbonImmutable::now())) {
                throw new InvalidArgumentException('RecordPickup::$occurredAt must not be in the future.');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'pickup';
    }

    public function subjectId(): string
    {
        return $this->pharmacyOrderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'pharmacy.medication_picked_up',
            actorId: $this->actorId,
            targetType: 'pickup',
            targetId: $this->dispensingId,
            metadata: [
                'pharmacy_order_id' => $this->pharmacyOrderId,
                'dispensing_id' => $this->dispensingId,
                'recipient_type' => $this->recipientType,
            ],
        );
    }
}
