<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Reverse the facility Charge for a reversed dispensing
 * (20-billing-and-payments-domain.md §113 / §135; ADR-189 D-F).
 *
 * SYSTEM command — dispatched only by the `OnMedicationDispensingReversed`
 * listener. No `AuthorizesAgainst` / `Auditable` / key (IC §7.3). Idempotent:
 * `idempotencyKey` defaults to `reversalEventId`; the handler no-ops on an
 * already-reversed / -cancelled billable event.
 */
final readonly class ReverseBillableEvent
{
    public string $reasonCode;

    public string $idempotencyKey;

    public function __construct(
        public string $reversalEventId,
        public string $dispensingId,
        string $reasonCode,
        public string $occurredAt,
        ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($reversalEventId) || ! Str::isUuid($dispensingId)) {
            throw new InvalidArgumentException('ReverseBillableEvent ids must be UUIDs (IC §3).');
        }

        $reasonCode = strtoupper(trim($reasonCode));
        if ($reasonCode === '') {
            throw new InvalidArgumentException('ReverseBillableEvent::$reasonCode is required (IC §3).');
        }

        try {
            CarbonImmutable::parse($occurredAt);
        } catch (Throwable) {
            throw new InvalidArgumentException('ReverseBillableEvent::$occurredAt must be an ISO-8601 datetime.');
        }

        $this->reasonCode = $reasonCode;
        $this->idempotencyKey = $idempotencyKey ?? $reversalEventId;
    }
}
