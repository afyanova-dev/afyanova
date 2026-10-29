<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Clinical\Contracts\Dto\PrescribingDecisionSummary;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Pharmacy\Domain\DispensingReader;
use App\Domains\Pharmacy\Domain\DispensingRepository;
use App\Domains\Pharmacy\Domain\Exception\DispensingExceedsAuthorizedQuantity;
use App\Domains\Pharmacy\Domain\Exception\DispensingFacilityMismatch;
use App\Domains\Pharmacy\Domain\Exception\DispensingItemNotInCatalog;
use App\Domains\Pharmacy\Domain\Exception\DispensingMedicationMismatch;
use App\Domains\Pharmacy\Domain\Exception\DispensingNotPermitted;
use App\Domains\Pharmacy\Domain\Exception\DispensingStockUnavailable;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\Model\Dispensing;
use App\Domains\Pharmacy\Domain\Model\Pickup;
use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\PickupRepository;
use App\Domains\Pharmacy\Domain\PrescriptionRepository;
use App\Domains\Pharmacy\Domain\Service\PharmacyOrderLifecycle;
use App\Domains\Pharmacy\Infrastructure\Sequence\DispensingNumberSequence;
use App\Domains\Pharmacy\Infrastructure\Sequence\PickupNumberSequence;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates `Dispense` (ADR-184 D-H). One bus transaction; the nested
 * `InventoryApi` calls dispatch Inventory commands that join it via a Postgres
 * SAVEPOINT (the `CompleteCaseHandler` / `AddProcedureNoteHandler` precedent) —
 * a `recordConsumption` failure rolls back the whole `Dispense`, reservation
 * included.
 *
 * `PharmacyOrderRepository` is the FIRST repository parameter so the bus
 * resolves `PharmacyOrderPolicy::dispense` (per-subject, at the order's facility).
 *
 * Flow: lock the order (`getByIdForUpdate` = the §112 concurrency guard) →
 * `assertCanDispense` → resolve + facility-check the location →
 * financial-clearance seam (no-op in Pharm.3) → FIRST dispensing: snapshot the
 * C.10 authorised quantity from Clinical (block a DISCONTINUED / SUPERSEDED
 * decision) / SUBSEQUENT: reuse the frozen snapshot → §112 / §113 check →
 * availability pre-check → `reserveStock` → `recordConsumption(reservationId)`
 * → write the `Dispensing` row (records `MedicationDispensed`) → advance the
 * order rollup.
 */
final class DispenseHandler
{
    public function __construct(
        private readonly PharmacyOrderRepository $orders,
        private readonly PrescriptionRepository $prescriptions,
        private readonly DispensingRepository $dispensings,
        private readonly DispensingReader $dispensingReader,
        private readonly PharmacyOrderLifecycle $lifecycle,
        private readonly DispensingNumberSequence $numbers,
        private readonly InventoryApi $inventory,
        private readonly ClinicalApi $clinical,
        private readonly TenantContext $context,
        private readonly PickupRepository $pickups,
        private readonly PickupNumberSequence $pickupNumbers,
    ) {}

    public function __invoke(Dispense $command): DispenseResult
    {
        $order = $this->orders->getByIdForUpdate($command->pharmacyOrderId);

        $this->lifecycle->assertCanDispense($command->pharmacyOrderId, (string) $order->status);

        $location = $this->inventory->getStockLocation($command->locationId)
            ?? throw NotFound::aggregate('StockLocation', $command->locationId);

        if ($location->facilityId !== (string) $order->facility_id) {
            throw DispensingFacilityMismatch::forLocation(
                $command->locationId,
                $location->facilityId,
                (string) $order->facility_id,
            );
        }

        $item = $this->inventory->getItem($command->itemId);
        if ($item === null) {
            throw NotFound::aggregate('InventoryItem', $command->itemId);
        }
        if (! $item->active || $item->itemType !== 'MEDICATION') {
            throw new DispensingItemNotInCatalog();
        }

        $prescription = $this->prescriptions->getById((string) $order->prescription_id);
        if (
            $prescription->source === Prescription::SOURCE_EXTERNAL_PAPER
            && $prescription->inventory_item_id !== $command->itemId
        ) {
            throw new DispensingMedicationMismatch();
        }

        [$authorizedValue, $authorizedUnit] = $this->resolveAuthorizedQuantity(
            $command->pharmacyOrderId,
            $prescription->prescribing_decision_id !== null ? (string) $prescription->prescribing_decision_id : null,
        );

        $cumulative = self::scale($this->dispensingReader->cumulativeForOrder($command->pharmacyOrderId));
        $quantity = self::scale($command->quantity);

        if ($authorizedValue !== null && bccomp(bcadd($cumulative, $quantity, 4), $authorizedValue, 4) > 0) {
            throw DispensingExceedsAuthorizedQuantity::forOrder(
                $command->pharmacyOrderId,
                $authorizedValue,
                $cumulative,
                $quantity,
            );
        }

        $available = self::scale($this->inventory->getAvailableStock($command->itemId, (string) $order->facility_id)->totalAvailableQty);
        if (bccomp($available, $quantity, 4) < 0) {
            throw DispensingStockUnavailable::forItem($command->itemId, $quantity, $available);
        }

        $reservationId = (string) Str::uuid7();
        $movementId = (string) Str::uuid7();

        $this->inventory->reserveStock(
            reservationId: $reservationId,
            actorId: $command->actorId,
            itemId: $command->itemId,
            locationId: $command->locationId,
            quantity: $command->quantity,
            consumerType: 'PHARMACY_ORDER',
            consumerRef: $command->pharmacyOrderId,
            batchNumber: $command->batchNumber,
        );

        $this->inventory->recordConsumption(
            movementId: $movementId,
            actorId: $command->actorId,
            itemId: $command->itemId,
            locationId: $command->locationId,
            quantity: $command->quantity,
            sourceType: 'PHARMACY',
            sourceRef: $command->dispensingId,
            batchNumber: $command->batchNumber,
            reservationId: $reservationId,
        );

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $dispensing = Dispensing::record([
            'id' => $command->dispensingId,
            'tenantId' => $this->context->tenantId(),
            'facilityId' => (string) $order->facility_id,
            'pharmacyOrderId' => $command->pharmacyOrderId,
            'prescriptionId' => (string) $prescription->id,
            'patientId' => (string) $order->patient_id,
            'dispensingNumber' => $this->numbers->next($this->context->tenantId()),
            'itemId' => $command->itemId,
            'locationId' => $command->locationId,
            'batchNumber' => $command->batchNumber,
            'quantity' => $quantity,
            'quantityUnit' => $authorizedUnit,
            'authorizedQuantityValue' => $authorizedValue,
            'authorizedQuantityUnit' => $authorizedUnit,
            'reservationId' => $reservationId,
            'movementId' => $movementId,
            'financialClearanceRef' => null,
            'dispensedByUserId' => $command->actorId,
            'occurredAt' => $occurredAt,
            'recordedAt' => $now,
        ]);

        $this->dispensings->save($dispensing);

        $order->applyDispensing(bcadd($cumulative, $quantity, 4), $authorizedValue);
        $this->orders->save($order);

        // ADR-186 D-C — auto-create the Pickup READY_FOR_PICKUP in the same txn.
        $pickup = Pickup::open(
            id: (string) Str::uuid7(),
            tenantId: $this->context->tenantId(),
            facilityId: (string) $order->facility_id,
            dispensingId: (string) $dispensing->id,
            pharmacyOrderId: $command->pharmacyOrderId,
            prescriptionId: (string) $prescription->id,
            patientId: (string) $order->patient_id,
            pickupNumber: $this->pickupNumbers->next($this->context->tenantId()),
            occurredAt: $now,
            recordedAt: $now,
        );
        $this->pickups->save($pickup);

        return new DispenseResult(
            dispensingId: (string) $dispensing->id,
            dispensingNumber: (string) $dispensing->dispensing_number,
            reservationId: $reservationId,
            movementId: $movementId,
            orderStatus: (string) $order->status,
            pickupId: (string) $pickup->id,
        );
    }

    /**
     * FIRST dispensing → snapshot from Clinical (blocking a DISCONTINUED /
     * SUPERSEDED decision); SUBSEQUENT → the frozen snapshot on dispensing #1.
     * A paper-transcribed prescription carries no decision — the ceiling is
     * skipped (NULL ⇒ `dispensed_without_ceiling`); the pharmacist, the typed
     * quantity, and the stock check govern instead.
     *
     * @return array{0: numeric-string|null, 1: string|null} [authorized_quantity_value, unit]
     */
    private function resolveAuthorizedQuantity(string $pharmacyOrderId, ?string $prescribingDecisionId): array
    {
        $existing = $this->dispensingReader->forOrder($pharmacyOrderId);
        if ($existing !== []) {
            $frozen = $existing[0]->authorizedQuantityValue;

            return [$frozen !== null ? self::scale($frozen) : null, $existing[0]->authorizedQuantityUnit];
        }

        if ($prescribingDecisionId === null || $prescribingDecisionId === '') {
            return [null, null];
        }

        $decision = $this->clinical->getPrescribingDecision($prescribingDecisionId);

        $this->assertDecisionDispensable($decision, $prescribingDecisionId);

        return [
            $decision->quantityValue !== null ? self::scale($decision->quantityValue) : null,
            $decision->quantityUnit,
        ];
    }

    private function assertDecisionDispensable(PrescribingDecisionSummary $decision, string $prescribingDecisionId): void
    {
        if ($decision->status === 'DISCONTINUED') {
            throw DispensingNotPermitted::decisionDiscontinued($prescribingDecisionId);
        }

        if ($decision->status === 'SUPERSEDED') {
            throw DispensingNotPermitted::decisionSuperseded(
                $prescribingDecisionId,
                (string) $decision->supersededByPrescribingDecisionId,
            );
        }
    }

    /**
     * Fixed-scale decimal string — the `Procurement\Domain\Quantity::of` house
     * pattern (ADR-184 D-L: no new Pharmacy value object; no float).
     *
     * @return numeric-string
     */
    private static function scale(string $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("Dispense: non-numeric quantity [{$value}].");
        }

        return bcadd($value, '0', 4);
    }
}
