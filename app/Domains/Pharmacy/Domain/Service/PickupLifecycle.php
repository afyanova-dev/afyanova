<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Service;

use App\Domains\Pharmacy\Domain\Exception\IllegalPickupTransition;
use App\Domains\Pharmacy\Domain\ValueObject\PickupStatus;

/**
 * Medication Pickup lifecycle rules (16-pharmacy-domain.md §29; ADR-186 D-D).
 * Pure domain logic, no I/O.
 *
 *   READY_FOR_PICKUP → PICKED_UP           (RecordPickup)
 *   NOT_COLLECTED    → PICKED_UP           (RecordPickup — late collection)
 *   READY_FOR_PICKUP → NOT_COLLECTED       (MarkPickupNotCollected)
 *   {READY_FOR_PICKUP | NOT_COLLECTED} → CANCELLED  (CancelPickup / auto on reversal)
 *
 * PICKED_UP and CANCELLED are hard-terminal.
 */
final class PickupLifecycle
{
    public function assertCanRecord(string $status): void
    {
        $this->assert('RecordPickup', $status, [
            PickupStatus::READY_FOR_PICKUP,
            PickupStatus::NOT_COLLECTED,
        ]);
    }

    public function assertCanMarkNotCollected(string $status): void
    {
        $this->assert('MarkPickupNotCollected', $status, [PickupStatus::READY_FOR_PICKUP]);
    }

    public function assertCanCancel(string $status): void
    {
        $this->assert('CancelPickup', $status, [
            PickupStatus::READY_FOR_PICKUP,
            PickupStatus::NOT_COLLECTED,
        ]);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assert(string $action, string $status, array $allowed): void
    {
        if (! in_array($status, $allowed, true)) {
            throw IllegalPickupTransition::fromStatus($action, $status);
        }
    }
}
