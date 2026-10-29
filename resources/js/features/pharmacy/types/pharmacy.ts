/**
 * Pharmacy workspace prop mirrors. Shapes follow the backend presentation
 * DTOs (`App\Domains\Pharmacy\Presentation\Http\DTO\Rx*.php`, all
 * `#[TypeScript]`-marked) and the `Pharmacy/Index` extras in
 * `PharmacyController` — hand-mirrored field for field. Add fields here
 * only after verifying them against the backend; the backend owns the
 * contract.
 */

export interface RxPrescriptionItem {
  prescriptionId: string;
  prescriptionNumber: string;
  patientId: string;
  patientDisplayName: string;
  patientNumber: string;
  patientAge?: number | null;
  patientSex?: string;
  source: string;
  status: string;
  medicationName: string | null;
  medicationCode: string | null;
  inventoryItemId: string | null;
  prescriberName: string | null;
  occurredAt: string;
  recordedAt: string;
  route: string | null;
  urgency: string | null;
  dose: string | null;
  frequency: string | null;
  duration: string | null;
  prescribedQuantity: string | null;
  dosingInstruction: string | null;
  transcriptionNote: string | null;
  externalIntakeId: string | null;
  lastDispenserName: string | null;
}

export interface RxOrderItem {
  pharmacyOrderId: string;
  orderNumber: string;
  prescriptionId: string;
  patientId: string;
  patientDisplayName: string;
  patientNumber: string;
  patientAge?: number | null;
  patientSex?: string;
  medicationName: string | null;
  status: string;
  priority: string;
  dispensingCount: number;
  occurredAt: string;
  dispensings: RxDispensingItem[];
  externalIntakeId: string | null;
}

export interface RxDispensingItem {
  dispensingId: string;
  dispensingNumber: string;
  itemCode: string;
  itemName: string;
  quantity: string;
  quantityUnit: string;
  status: string;
  pickupStatus: string | null;
  reversalId: string | null;
  receivedAt: string;
}

export interface RxPatientStack {
  patientId: string;
  displayName: string;
  patientNumber: string;
  prescriptions: RxPrescriptionItem[];
  orders: RxOrderItem[];
}

export interface RxPatientResult {
  patientId: string;
  patientNumber: string;
  displayName: string;
  sex: string;
  status: string;
  isProvisional: boolean;
  hasAccessibleFacilityRegistration: boolean;
  survivingPatientId: string | null;
}

export interface RxItemOption {
  itemId: string;
  code: string;
  name: string;
  uom: string;
  active: boolean;
  itemType: string;
  trackBatch: boolean;
}

export interface RxLocationOption {
  locationId: string;
  code: string;
  name: string;
}

/**
 * One per-location, per-batch stock line for the selected dispense item.
 * `eligible` is the server's Phase 4 availability flag — an expired batch is not
 * dispensable; the panel excludes it from the location totals it shows.
 */
export interface RxStockEntry {
  locationId: string;
  batchId: string | null;
  batchNumber: string | null;
  expiryDate: string | null;
  onHand: string;
  reserved: string;
  available: string;
  uom: string;
  eligible: boolean;
}

export interface PharmacyPatientAllergy {
  substanceName: string;
  severity: string;
  reaction: string | null;
}

export interface PharmacyTranscribePatient {
  patientId: string;
  patientNumber: string;
  displayName: string;
}

export interface PharmacyBoardProps {
  facilityId: string;
  canTranscribe: boolean;
  canCreatePharmacyPatient: boolean;
  externalIntake: boolean;
  canManageOrders: boolean;
  canDispense: boolean;
  canReverse: boolean;
  canRecordPickup: boolean;
  prescriptions: RxPrescriptionItem[];
  prescriptionStatus: string | null;
  orders: RxOrderItem[];
  orderStatus: string | null;
  selectedOrderId: string | null;
  selectedOrderNumber: string | null;
  selectedOrderStatus: string | null;
  selectedPrescriptionId: string | null;
  selectedPrescriptionNumber: string | null;
  selectedMedicationName: string | null;
  selectedMedicationCode: string | null;
  selectedDose: string | null;
  selectedFrequency: string | null;
  selectedDuration: string | null;
  selectedPrescribedQuantity: string | null;
  selectedDosingInstruction: string | null;
  selectedPrescriberName: string | null;
  selectedTranscriptionNote: string | null;
  selectedRxStatus: string | null;
  selectedRxOccurredAt: string | null;
  selectedSource: string | null;
  selectedPatientId: string | null;
  selectedPatientDisplayName: string | null;
  selectedPatientNumber: string | null;
  selectedPatientDateOfBirth: string | null;
  selectedPatientSex: string | null;
  selectedPatientAllergies: PharmacyPatientAllergy[] | null;
  view: string | null;
  viewPatient: RxPatientStack | null;
  dispensings: RxDispensingItem[];
  items: RxItemOption[];
  locations: RxLocationOption[];
  selectedItemId: string | null;
  selectedItemCode: string | null;
  selectedItemName: string | null;
  selectedItemUom: string | null;
  availabilityStatus: string;
  stockByLocation: RxStockEntry[];
  searchError: string | null;
  query: string | null;
  searchResults: RxPatientResult[] | null;
  transcribePatient: PharmacyTranscribePatient | null;
  transcribeAllergies: PharmacyPatientAllergy[];
  transcribeAllergiesAvailable: boolean;
  errors?: Record<string, string>;
}
