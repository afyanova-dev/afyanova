<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Pharmacy workspace root props (UI-SPECIFICATIONS.md §11.9, phase 1:
 * transcribe → accept → order → validate → dispense → pickup).
 *
 * Availability is read live for the selected item only — never snapshotted
 * into the option list. Permission flags drive show/disable; every writer
 * re-authorizes server-side.
 */
#[TypeScript]
final readonly class PharmacyDashboardProps
{
    /**
     * @param  list<RxPrescriptionItem>  $prescriptions
     * @param  list<RxOrderItem>  $orders
     * @param  list<RxDispensingItem>  $dispensings
     * @param  list<RxItemOption>  $items
     * @param  list<RxLocationOption>  $locations
     * @param  list<RxStockEntry>  $stockByLocation
     */
    public function __construct(
        public string $facilityId,
        public bool $canTranscribe,
        public bool $canManageOrders,
        public bool $canDispense,
        public bool $canReverse,
        public bool $canRecordPickup,
        #[LiteralTypeScriptType('Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxPrescriptionItem>')]
        public array $prescriptions = [],
        public ?string $prescriptionStatus = null,
        #[LiteralTypeScriptType('Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxOrderItem>')]
        public array $orders = [],
        public ?string $orderStatus = null,
        public ?string $selectedOrderId = null,
        public ?string $selectedOrderNumber = null,
        public ?string $selectedOrderStatus = null,
        public ?string $selectedPrescriptionId = null,
        public ?string $selectedPrescriptionNumber = null,
        public ?string $selectedMedicationName = null,
        public ?string $selectedMedicationCode = null,
        public ?string $selectedDose = null,
        public ?string $selectedFrequency = null,
        public ?string $selectedDuration = null,
        public ?string $selectedPrescribedQuantity = null,
        public ?string $selectedDosingInstruction = null,
        public ?string $selectedPrescriberName = null,
        public ?string $selectedTranscriptionNote = null,
        public ?string $selectedRxStatus = null,
        public ?string $selectedRxOccurredAt = null,
        public ?string $selectedSource = null,
        public ?string $selectedPatientId = null,
        public ?string $selectedPatientDisplayName = null,
        public ?string $selectedPatientNumber = null,
        public ?string $selectedPatientDateOfBirth = null,
        public ?string $selectedPatientSex = null,
        public ?array $selectedPatientAllergies = null,
        public ?string $view = null,
        public ?RxPatientStack $viewPatient = null,
        #[LiteralTypeScriptType('Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxDispensingItem>')]
        public array $dispensings = [],
        #[LiteralTypeScriptType('Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxItemOption>')]
        public array $items = [],
        #[LiteralTypeScriptType('Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxLocationOption>')]
        public array $locations = [],
        public ?string $selectedItemId = null,
        public ?string $selectedItemCode = null,
        public ?string $selectedItemName = null,
        public ?string $selectedItemUom = null,
        /**
         * Truthful availability state for the selected item: `NOT_CHECKED`
         * (no item selected), `AVAILABLE` (read succeeded — the list may still
         * be empty, i.e. genuinely zero), or `FAILED` (the read threw). A caught
         * read error is never presented as zero or success.
         */
        public string $availabilityStatus = 'NOT_CHECKED',
        #[LiteralTypeScriptType('Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxStockEntry>')]
        public array $stockByLocation = [],
        public ?string $searchError = null,
    ) {}
}
