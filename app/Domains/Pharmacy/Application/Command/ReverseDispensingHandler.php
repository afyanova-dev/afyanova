<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Pharmacy\Domain\DispensingReader;
use App\Domains\Pharmacy\Domain\DispensingReversalRepository;
use App\Domains\Pharmacy\Domain\Exception\DispensingAlreadyReversed;
use App\Domains\Pharmacy\Domain\Exception\DispensingNotReversible;
use App\Domains\Pharmacy\Domain\Exception\DispensingReversalMismatch;
use App\Domains\Pharmacy\Domain\Model\Dispensing;
use App\Domains\Pharmacy\Domain\Model\DispensingReversal;
use App\Domains\Pharmacy\Domain\Model\Pickup;
use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\PickupRepository;
use App\Domains\Pharmacy\Domain\Service\PharmacyOrderLifecycle;
use App\Domains\Pharmacy\Infrastructure\Sequence\DispensingReversalNumberSequence;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Orchestrates `ReverseDispensing` (ADR-185 D-D). One bus transaction; the
 * nested `InventoryApi::reverseConsumption` = a Postgres savepoint (the
 * `DispenseHandler` / `CompleteCaseHandler` precedent) — a failure there rolls
 * back the whole reversal.
 *
 * `PharmacyOrderRepository` is FIRST so the bus resolves
 * `PharmacyOrderPolicy::reverse` (per-subject `pharmacy.dispensing.reverse` at
 * the order's facility).
 *
 * Flow: lock the order (the §112 concurrency guard) → load the dispensing
 * (COMPLETED, belongs to this order) → assert not already reversed → **ADR-186
 * D-E: lock the Pickup row; a PICKED_UP dispensing's reversal is REFUSED** (the
 * medication has left the pharmacy — the ledger must not gain it back) → assert
 * the order status permits a reversal → InventoryApi::reverseConsumption (the
 * compensating IN) → write the DispensingReversal (records
 * MedicationDispensingReversed) → recompute the net cumulative and re-roll the
 * order status → CANCEL a READY_FOR_PICKUP / NOT_COLLECTED pickup.
 */
final class ReverseDispensingHandler
{
    public function __construct(
        private readonly PharmacyOrderRepository $orders,
        private readonly DispensingReader $dispensingReader,
        private readonly DispensingReversalRepository $reversals,
        private readonly PharmacyOrderLifecycle $lifecycle,
        private readonly DispensingReversalNumberSequence $numbers,
        private readonly InventoryApi $inventory,
        private readonly TenantContext $context,
        private readonly PickupRepository $pickups,
    ) {}

    public function __invoke(ReverseDispensing $command): ReverseDispensingResult
    {
        $order = $this->orders->getByIdForUpdate($command->pharmacyOrderId);

        $dispensing = $this->dispensingReader->summary($command->dispensingId);

        if ($dispensing->pharmacyOrderId !== $command->pharmacyOrderId) {
            throw DispensingReversalMismatch::orderDispensing(
                $command->pharmacyOrderId,
                $command->dispensingId,
                $dispensing->pharmacyOrderId,
            );
        }

        if ($dispensing->status !== Dispensing::STATUS_COMPLETED) {
            throw DispensingNotReversible::status($command->dispensingId, $dispensing->status);
        }

        // "already reversed" is the more specific error — check it before the
        // order-status guard (a fully-reversed order is back to VALIDATED).
        if ($this->reversals->existsForDispensing($command->dispensingId)) {
            throw DispensingAlreadyReversed::forDispensing($command->dispensingId);
        }

        // ADR-186 D-E — lock the Pickup row (serialises with RecordPickup); a
        // PICKED_UP dispensing cannot undergo a stock-returning reversal.
        $pickup = null;
        try {
            $pickup = $this->pickups->forDispensingForUpdate($command->dispensingId);
        } catch (NotFound) {
            // pre-Pharm.3c dispensing — no pickup row; the reversal proceeds.
        }

        if ($pickup instanceof Pickup && $pickup->isPickedUp()) {
            throw DispensingNotReversible::pickedUp($command->dispensingId);
        }

        $this->lifecycle->assertCanReverse($command->pharmacyOrderId, (string) $order->status);

        $reversalMovementId = (string) Str::uuid7();

        $this->inventory->reverseConsumption(
            reversalMovementId: $reversalMovementId,
            actorId: $command->actorId,
            originalMovementId: $dispensing->movementId,
            sourceRef: $command->reversalId,
            reason: 'dispensing reversal: '.$command->reasonCode,
        );

        $now = CarbonImmutable::now();

        $reversal = DispensingReversal::record([
            'id' => $command->reversalId,
            'tenantId' => $this->context->tenantId(),
            'facilityId' => $dispensing->facilityId,
            'dispensingId' => $command->dispensingId,
            'pharmacyOrderId' => $command->pharmacyOrderId,
            'prescriptionId' => $dispensing->prescriptionId,
            'patientId' => $dispensing->patientId,
            'reversalNumber' => $this->numbers->next($this->context->tenantId()),
            'itemId' => $dispensing->itemId,
            'locationId' => $dispensing->locationId,
            'batchNumber' => $dispensing->batchNumber,
            'quantity' => self::scale($dispensing->quantity),
            'reversalMovementId' => $reversalMovementId,
            'reasonCode' => $command->reasonCode,
            'reasonNote' => $command->reasonNote,
            'reversedByUserId' => $command->actorId,
            'occurredAt' => $now,
            'recordedAt' => $now,
        ]);

        try {
            $this->reversals->save($reversal);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'rx_dispensing_reversals_one_per_dispensing')) {
                throw DispensingAlreadyReversed::forDispensing($command->dispensingId);
            }
            throw $e;
        }

        // cumulativeForOrder now nets the reversal just saved.
        $newCumulative = self::scale($this->dispensingReader->cumulativeForOrder($command->pharmacyOrderId));

        $order->applyReversal(
            $newCumulative,
            $dispensing->authorizedQuantityValue !== null ? self::scale($dispensing->authorizedQuantityValue) : null,
        );
        $this->orders->save($order);

        // ADR-186 D-E — a READY_FOR_PICKUP / NOT_COLLECTED pickup is now moot.
        if ($pickup instanceof Pickup
            && in_array((string) $pickup->status, [Pickup::STATUS_READY_FOR_PICKUP, Pickup::STATUS_NOT_COLLECTED], true)) {
            $pickup->cancel('DISPENSING_REVERSED', null);
            $this->pickups->save($pickup);
        }

        return new ReverseDispensingResult(
            reversalId: (string) $reversal->id,
            reversalNumber: (string) $reversal->reversal_number,
            reversalMovementId: $reversalMovementId,
            orderStatus: (string) $order->status,
        );
    }

    /** @return numeric-string */
    private static function scale(string $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("ReverseDispensing: non-numeric quantity [{$value}].");
        }

        return bcadd($value, '0', 4);
    }
}
