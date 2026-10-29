<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\Presenter;

use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Clinical\Contracts\Dto\AllergySummary;
use App\Domains\Inventory\Contracts\Dto\InventoryItemRef;
use App\Domains\Inventory\Contracts\Dto\ItemSummary;
use App\Domains\Inventory\Contracts\Dto\StockLocationSummary;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Pharmacy\Application\Query\GetPharmacyOrder;
use App\Domains\Pharmacy\Application\Query\GetPharmacyOrderDispensings;
use App\Domains\Pharmacy\Application\Query\GetPrescription;
use App\Domains\Pharmacy\Application\Query\SearchPharmacyOrders;
use App\Domains\Pharmacy\Application\Query\SearchPickups;
use App\Domains\Pharmacy\Application\Query\SearchPrescriptions;
use App\Domains\Pharmacy\Contracts\Dto\DispensingSummary;
use App\Domains\Pharmacy\Contracts\Dto\PharmacyOrderSummary;
use App\Domains\Pharmacy\Contracts\Dto\PickupSummary;
use App\Domains\Pharmacy\Contracts\Dto\PrescriptionSummary;
use App\Domains\Pharmacy\Presentation\Http\DTO\PharmacyDashboardProps;
use App\Domains\Pharmacy\Presentation\Http\DTO\RxDispensingItem;
use App\Domains\Pharmacy\Presentation\Http\DTO\RxItemOption;
use App\Domains\Pharmacy\Presentation\Http\DTO\RxLocationOption;
use App\Domains\Pharmacy\Presentation\Http\DTO\RxOrderItem;
use App\Domains\Pharmacy\Presentation\Http\DTO\RxPatientStack;
use App\Domains\Pharmacy\Presentation\Http\DTO\RxPrescriptionItem;
use App\Domains\Pharmacy\Presentation\Http\DTO\RxStockEntry;
use App\Models\User;
use App\Shared\Bus\QueryBus;
use Carbon\CarbonImmutable;

/**
 * Pharmacy workspace read model (UI-SPECIFICATIONS.md §11.9, phase 1).
 * Composes Pharmacy's own protected queries + Patient identity + Inventory
 * catalog reads (all through published contracts — never foreign tables).
 * Stock availability is read live for the selected item only.
 */
final class PharmacyPresenter
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly PatientApi $patientApi,
        private readonly InventoryApi $inventoryApi,
        private readonly ClinicalApi $clinicalApi,
    ) {}

    /**
     * @param  array<string, bool>  $permissions
     */
    public function present(
        string $facilityId,
        ?string $prescriptionStatus,
        ?string $orderStatus,
        ?string $selectedOrderId,
        ?string $selectedPrescriptionId,
        ?string $selectedItemId,
        ?string $view,
        ?string $viewPatientId,
        array $permissions,
    ): PharmacyDashboardProps {
        /** @var list<PrescriptionSummary> $prescriptions */
        $prescriptions = $this->queryBus->dispatch(new SearchPrescriptions($facilityId, $prescriptionStatus));
        /** @var list<PharmacyOrderSummary> $orders */
        $orders = $this->queryBus->dispatch(new SearchPharmacyOrders($facilityId, $orderStatus));

        $patientIds = array_values(array_unique(array_filter(array_merge(
            array_map(static fn (PrescriptionSummary $p): string => $p->patientId, $prescriptions),
            array_map(static fn (PharmacyOrderSummary $o): string => $o->patientId, $orders),
        ))));
        /** @var array<string, PatientSummary> $patients */
        $patients = $patientIds !== [] ? $this->patientApi->getPatientSummaries($patientIds) : [];

        $nameOf = static function (string $patientId) use ($patients): array {
            $p = $patients[$patientId] ?? null;

            return [$p?->displayName ?? 'Unknown patient', $p?->patientNumber ?? '—'];
        };
        $demographicsOf = static function (string $patientId) use ($patients): array {
            $patient = $patients[$patientId] ?? null;
            $age = $patient?->dateOfBirth !== null
                ? CarbonImmutable::parse($patient->dateOfBirth)->age
                : null;

            return [$age, $patient?->sex ?? 'UNKNOWN'];
        };

        /** @var array<string, list<RxDispensingItem>> $dispensingsByOrder */
        $dispensingsByOrder = [];
        $orderItems = [];
        $dispensingActorsByPrescription = [];
        $medicationNameByPrescription = [];
        $externalIntakeByPrescription = [];
        foreach ($prescriptions as $prescription) {
            $medicationNameByPrescription[$prescription->prescriptionId] = $prescription->medicationName;
            $externalIntakeByPrescription[$prescription->prescriptionId] = $prescription->externalIntakeId;
        }
        /** @var array<string, InventoryItemRef|null> $inventoryItemsById */
        $inventoryItemsById = [];
        $pickupByDispensing = [];
        if ($orders !== []) {
            /** @var list<PickupSummary> $allPickups */
            $allPickups = $this->queryBus->dispatch(new SearchPickups($facilityId));
            foreach ($allPickups as $pickup) {
                $pickupByDispensing[$pickup->dispensingId] = $pickup->status;
            }
        }
        foreach ($orders as $o) {
            /** @var list<DispensingSummary> $d */
            $d = $this->queryBus->dispatch(new GetPharmacyOrderDispensings($o->pharmacyOrderId));
            $orderDispensings = [];
            foreach ($d as $dispensing) {
                $dispensingActorsByPrescription[$o->prescriptionId][] = [
                    'userId' => $dispensing->dispensedByUserId,
                    'occurredAt' => $dispensing->occurredAt,
                ];
                if (! array_key_exists($dispensing->itemId, $inventoryItemsById)) {
                    try {
                        $inventoryItemsById[$dispensing->itemId] = $this->inventoryApi->getItem($dispensing->itemId);
                    } catch (\Throwable) {
                        $inventoryItemsById[$dispensing->itemId] = null;
                    }
                }
                $item = $inventoryItemsById[$dispensing->itemId];
                $orderDispensings[] = new RxDispensingItem(
                    dispensingId: $dispensing->dispensingId,
                    dispensingNumber: $dispensing->dispensingNumber,
                    itemCode: $item?->code ?? '—',
                    itemName: $item?->name ?? 'Unknown item',
                    quantity: $dispensing->quantity,
                    quantityUnit: $dispensing->quantityUnit ?? $item?->uom ?? '',
                    status: $dispensing->status,
                    pickupStatus: $pickupByDispensing[$dispensing->dispensingId] ?? null,
                    reversalId: $dispensing->reversalId,
                    receivedAt: $dispensing->occurredAt,
                );
            }
            $dispensingsByOrder[$o->pharmacyOrderId] = $orderDispensings;
            [$name, $number] = $nameOf($o->patientId);
            [$age, $sex] = $demographicsOf($o->patientId);
            $orderItems[] = new RxOrderItem(
                pharmacyOrderId: $o->pharmacyOrderId,
                orderNumber: $o->orderNumber,
                prescriptionId: $o->prescriptionId,
                patientId: $o->patientId,
                patientDisplayName: $name,
                patientNumber: $number,
                patientAge: $age,
                patientSex: $sex,
                medicationName: $medicationNameByPrescription[$o->prescriptionId] ?? null,
                status: $o->status,
                priority: $o->priority,
                dispensingCount: count($orderDispensings),
                occurredAt: $o->occurredAt,
                dispensings: $orderDispensings,
                externalIntakeId: $externalIntakeByPrescription[$o->prescriptionId] ?? null,
            );
        }

        $dispenserIds = array_values(array_unique(array_merge(
            ...array_map(
                static fn (array $actors): array => array_column($actors, 'userId'),
                array_values($dispensingActorsByPrescription),
            ),
        )));
        $dispenserNames = $dispenserIds !== []
            ? User::query()->whereIn('id', $dispenserIds)->pluck('name', 'id')->all()
            : [];
        $lastDispenserByPrescription = [];
        foreach ($dispensingActorsByPrescription as $prescriptionId => $actors) {
            usort($actors, static fn (array $left, array $right): int => strcmp($right['occurredAt'], $left['occurredAt']));
            $lastActorId = $actors[0]['userId'] ?? null;
            $lastDispenserByPrescription[$prescriptionId] = $lastActorId !== null
                ? ($dispenserNames[$lastActorId] ?? 'Unknown dispenser')
                : null;
        }

        $prescriptionItems = array_map(static function (PrescriptionSummary $p) use ($nameOf, $demographicsOf, $lastDispenserByPrescription): RxPrescriptionItem {
            [$name, $number] = $nameOf($p->patientId);
            [$age, $sex] = $demographicsOf($p->patientId);

            return new RxPrescriptionItem(
                prescriptionId: $p->prescriptionId,
                prescriptionNumber: $p->prescriptionNumber,
                patientId: $p->patientId,
                patientDisplayName: $name,
                patientNumber: $number,
                patientAge: $age,
                patientSex: $sex,
                source: $p->source ?? 'ELECTRONIC',
                status: $p->status,
                medicationName: $p->medicationName,
                medicationCode: $p->medicationCode,
                inventoryItemId: $p->inventoryItemId,
                prescriberName: $p->prescriberName ?? null,
                occurredAt: $p->occurredAt,
                recordedAt: $p->recordedAt,
                route: $p->route,
                urgency: $p->urgency,
                dose: $p->dose,
                frequency: $p->frequency,
                duration: $p->duration,
                prescribedQuantity: $p->prescribedQuantity,
                dosingInstruction: $p->dosingInstruction,
                transcriptionNote: $p->transcriptionNote,
                externalIntakeId: $p->externalIntakeId,
                lastDispenserName: $lastDispenserByPrescription[$p->prescriptionId] ?? null,
            );
        }, $prescriptions);

        $selectedOrder = null;
        $dispensings = [];
        $selectedPatientId = null;
        $selectedPatientName = null;
        $selectedPatientNumber = null;
        $selectedPrescription = null;
        if ($selectedOrderId !== null) {
            foreach ($orders as $o) {
                if ($o->pharmacyOrderId === $selectedOrderId) {
                    $selectedOrder = $o;
                    break;
                }
            }
            if ($selectedOrder === null) {
                try {
                    /** @var PharmacyOrderSummary $selectedOrder */
                    $selectedOrder = $this->queryBus->dispatch(new GetPharmacyOrder($selectedOrderId));
                } catch (\Throwable) {
                    $selectedOrder = null;
                }
            }
            if ($selectedOrder !== null) {
                [$pname, $pnumber] = $nameOf($selectedOrder->patientId);
                $selectedPatientId = $selectedOrder->patientId;
                $selectedPatientName = $pname;
                $selectedPatientNumber = $pnumber;
                $selectedPrescription = null;
                try {
                    /** @var PrescriptionSummary $selectedPrescription */
                    $selectedPrescription = $this->queryBus->dispatch(new GetPrescription($selectedOrder->prescriptionId));
                } catch (\Throwable) {
                    $selectedPrescription = null;
                }
                $dispensings = $dispensingsByOrder[$selectedOrder->pharmacyOrderId] ?? [];
            }
        }

        /** @var list<ItemSummary> $items */
        $items = $this->inventoryApi->getItemSummaries('MEDICATION', true);
        $itemOptions = array_map(static fn (ItemSummary $i): RxItemOption => new RxItemOption(
            itemId: $i->itemId,
            code: $i->code,
            name: $i->name,
            uom: $i->uom,
            active: $i->active,
            itemType: $i->itemType,
            trackBatch: $i->trackBatch,
        ), $items);

        /** @var list<StockLocationSummary> $locations */
        $locations = $this->inventoryApi->getStockLocationSummaries($facilityId, true);
        $locationOptions = array_map(static fn (StockLocationSummary $l): RxLocationOption => new RxLocationOption(
            locationId: $l->locationId,
            code: $l->code,
            name: $l->name,
        ), $locations);

        $selectedItem = null;
        $availabilityStatus = 'NOT_CHECKED';
        /** @var list<RxStockEntry> $stockByLocation */
        $stockByLocation = [];
        if ($selectedItemId !== null) {
            foreach ($items as $i) {
                if ($i->itemId === $selectedItemId) {
                    $selectedItem = $i;
                    break;
                }
            }
            if ($selectedItem !== null) {
                try {
                    $stock = $this->inventoryApi->getAvailableStock($selectedItemId, $facilityId);
                    $availabilityStatus = 'AVAILABLE';
                    foreach ($stock->byLocation as $row) {
                        $stockByLocation[] = new RxStockEntry(
                            locationId: $row->locationId,
                            batchId: $row->batchId,
                            batchNumber: $row->batchNumber,
                            expiryDate: $row->expiryDate,
                            onHand: $row->onHandQty,
                            reserved: $row->reservedQty,
                            available: $row->availableQty,
                            uom: $row->uom,
                            eligible: $row->eligible,
                        );
                    }
                } catch (\Throwable) {
                    // A caught read error must never look like zero available.
                    $availabilityStatus = 'FAILED';
                    $stockByLocation = [];
                }
            }
        }

        $standalonePrescription = null;
        if ($selectedPrescriptionId !== null && $selectedOrder === null) {
            try {
                /** @var PrescriptionSummary $standalonePrescription */
                $standalonePrescription = $this->queryBus->dispatch(new GetPrescription($selectedPrescriptionId));
            } catch (\Throwable) {
                $standalonePrescription = null;
            }
            if ($standalonePrescription !== null) {
                $selectedPatientId = $standalonePrescription->patientId;
                [$standaloneName, $standaloneNumber] = $nameOf($standalonePrescription->patientId);
                $selectedPatientName = $standaloneName;
                $selectedPatientNumber = $standaloneNumber;
            }
        }

        $selectedPatientDob = null;
        $selectedPatientSex = null;
        $selectedPatientAllergies = [];
        if ($selectedPatientId !== null) {
            $pSummary = $patients[$selectedPatientId] ?? null;
            if ($pSummary === null) {
                try {
                    $pSummary = $this->patientApi->getPatientSummary($selectedPatientId);
                } catch (\Throwable) {
                    $pSummary = null;
                }
            }
            $selectedPatientDob = $pSummary?->dateOfBirth;
            $selectedPatientSex = $pSummary?->sex;

            try {
                /** @var list<AllergySummary> $als */
                $als = $this->clinicalApi->getPatientAllergies($selectedPatientId, 'ACTIVE', null);
                foreach ($als as $al) {
                    $selectedPatientAllergies[] = [
                        'substanceName' => $al->substanceName,
                        'severity' => $al->severity,
                        'reaction' => $al->reactionDescription,
                    ];
                }
            } catch (\Throwable) {
                $selectedPatientAllergies = [];
            }
        }

        $viewPatientStack = null;
        if ($view === 'patient' && $viewPatientId !== null) {
            $viewPatientStack = $this->patientStack($viewPatientId, $prescriptionItems, $orderItems, $nameOf);
        }

        return new PharmacyDashboardProps(
            facilityId: $facilityId,
            view: $view,
            viewPatient: $viewPatientStack,
            canTranscribe: $permissions['transcribe'] ?? false,
            canManageOrders: $permissions['orders'] ?? false,
            canDispense: $permissions['dispense'] ?? false,
            canReverse: $permissions['reverse'] ?? false,
            canRecordPickup: $permissions['pickup'] ?? false,
            prescriptions: array_values($prescriptionItems),
            prescriptionStatus: $prescriptionStatus,
            orders: array_values($orderItems),
            orderStatus: $orderStatus,
            selectedOrderId: $selectedOrder?->pharmacyOrderId,
            selectedOrderNumber: $selectedOrder?->orderNumber,
            selectedOrderStatus: $selectedOrder?->status,
            selectedPrescriptionId: $selectedPrescription?->prescriptionId ?? $standalonePrescription?->prescriptionId,
            selectedPrescriptionNumber: $selectedPrescription?->prescriptionNumber ?? $standalonePrescription?->prescriptionNumber,
            selectedMedicationName: $selectedPrescription?->medicationName ?? $standalonePrescription?->medicationName,
            selectedMedicationCode: $selectedPrescription?->medicationCode ?? $standalonePrescription?->medicationCode,
            selectedDose: $selectedPrescription?->dose ?? $standalonePrescription?->dose,
            selectedFrequency: $selectedPrescription?->frequency ?? $standalonePrescription?->frequency,
            selectedDuration: $selectedPrescription?->duration ?? $standalonePrescription?->duration,
            selectedPrescribedQuantity: $selectedPrescription?->prescribedQuantity ?? $standalonePrescription?->prescribedQuantity,
            selectedDosingInstruction: $selectedPrescription?->dosingInstruction ?? $standalonePrescription?->dosingInstruction,
            selectedPrescriberName: $selectedPrescription?->prescriberName ?? $standalonePrescription?->prescriberName,
            selectedTranscriptionNote: $selectedPrescription?->transcriptionNote ?? $standalonePrescription?->transcriptionNote,
            selectedRxStatus: $selectedPrescription?->status ?? $standalonePrescription?->status,
            selectedRxOccurredAt: $selectedPrescription?->occurredAt ?? $standalonePrescription?->occurredAt,
            selectedSource: $selectedPrescription?->source ?? $standalonePrescription?->source,
            selectedPatientId: $selectedPatientId,
            selectedPatientDisplayName: $selectedPatientName,
            selectedPatientNumber: $selectedPatientNumber,
            selectedPatientDateOfBirth: $selectedPatientDob,
            selectedPatientSex: $selectedPatientSex,
            selectedPatientAllergies: $selectedPatientAllergies,
            dispensings: $dispensings,
            items: array_values($itemOptions),
            locations: array_values($locationOptions),
            selectedItemId: $selectedItem?->itemId,
            selectedItemCode: $selectedItem?->code,
            selectedItemName: $selectedItem?->name,
            selectedItemUom: $selectedItem?->uom,
            availabilityStatus: $availabilityStatus,
            stockByLocation: array_values($stockByLocation),
        );
    }

    /**
     * @param  list<RxPrescriptionItem>  $prescriptionItems
     * @param  list<RxOrderItem>  $orderItems
     * @param  callable(string): array{0: string, 1: string}  $nameOf
     */
    private function patientStack(string $patientId, array $prescriptionItems, array $orderItems, callable $nameOf): ?RxPatientStack
    {
        [$name, $number] = $nameOf($patientId);
        if ($name === 'Unknown patient') {
            try {
                $summary = $this->patientApi->getPatientSummary($patientId);
                $name = $summary->displayName;
                $number = $summary->patientNumber;
            } catch (\Throwable) {
                return null;
            }
        }

        return new RxPatientStack(
            patientId: $patientId,
            displayName: $name,
            patientNumber: $number,
            prescriptions: array_values(array_filter(
                $prescriptionItems,
                static fn (RxPrescriptionItem $pi): bool => $pi->patientId === $patientId
            )),
            orders: array_values(array_filter(
                $orderItems,
                static fn (RxOrderItem $o): bool => $o->patientId === $patientId
            )),
        );
    }
}
