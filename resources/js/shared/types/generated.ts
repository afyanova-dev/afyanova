declare namespace App.Domains.Billing.Presentation.Http.DTO {
export type CashierChargeItem = {
chargeId: string;
description: string;
quantity: string;
unitPrice: string;
amount: string;
status: string;
currency: string;
occurredAt: string;
};
export type CashierDashboardProps = {
facilityId: string;
mySession: App.Domains.Billing.Presentation.Http.DTO.CashierSessionItem | null;
canManageSessions: boolean;
canManageInvoices: boolean;
canRecordPayments: boolean;
accountId: string | null;
accountNumber: string | null;
patientId: string | null;
patientDisplayName: string | null;
patientNumber: string | null;
outstanding: string | null;
chargedTotal: string | null;
settledTotal: string | null;
currency: string | null;
invoices: Array<App.Domains.Billing.Presentation.Http.DTO.CashierInvoiceItem>;
uninvoicedCharges: Array<App.Domains.Billing.Presentation.Http.DTO.CashierChargeItem>;
payments: Array<App.Domains.Billing.Presentation.Http.DTO.CashierPaymentItem>;
searchError: string | null;
query: string | null;
searchResults: Array<App.Domains.Billing.Presentation.Http.DTO.CashierPatientResult>|null;
};
export type CashierInvoiceItem = {
invoiceId: string;
invoiceNumber: string | null;
status: string;
net: string;
paidAmount: string;
paymentStatus: string;
derivedOutstanding: string;
currency: string;
lineCount: number;
issuedAt: string | null;
};
export type CashierPatientResult = {
patientId: string;
patientNumber: string;
displayName: string;
sex: string;
status: string;
isProvisional: boolean;
hasAccessibleFacilityRegistration: boolean;
survivingPatientId: string | null;
};
export type CashierPaymentItem = {
paymentId: string;
receiptNumber: string;
method: string;
amount: string;
status: string;
unallocatedAmount: string;
currency: string;
receivedAt: string;
};
export type CashierSessionItem = {
cashSessionId: string;
status: string;
openingFloat: string;
cashPaymentsTotal: string;
expectedCash: string;
countedCash: string | null;
variance: string | null;
currency: string;
openedAt: string;
closedAt: string | null;
};
export type CatalogDashboardProps = {
facilityId: string;
canManageCatalog: boolean;
canRetryCharges: boolean;
services: Array<App.Domains.Billing.Presentation.Http.DTO.CatalogServiceItem>;
category: string | null;
status: string | null;
selectedServiceId: string | null;
selectedCode: string | null;
selectedName: string | null;
selectedCategory: string | null;
selectedStatus: string | null;
facilityAmount: string | null;
facilityEffectiveFrom: string | null;
defaultAmount: string | null;
defaultEffectiveFrom: string | null;
currency: string;
pendingEvents: Array<App.Domains.Billing.Presentation.Http.DTO.CatalogPendingEventItem>;
tariffHistory: Array<App.Domains.Billing.Presentation.Http.DTO.CatalogTariffHistoryItem>;
medicationMaps: Array<App.Domains.Billing.Presentation.Http.DTO.CatalogItemMapItem>;
itemMapHistory: Array<App.Domains.Billing.Presentation.Http.DTO.CatalogItemMapHistoryItem>;
searchError: string | null;
};
export type CatalogItemMapHistoryItem = {
mapId: string;
invItemId: string;
billingServiceId: string;
serviceName: string | null;
active: boolean;
occurredAt: string;
};
export type CatalogItemMapItem = {
invItemId: string;
code: string;
name: string;
itemType: string;
itemActive: boolean;
mapped: boolean;
billingServiceId: string | null;
serviceCode: string | null;
serviceName: string | null;
serviceStatus: string | null;
amount: string | null;
resolvedScope: string | null;
currency: string | null;
mappedAt: string | null;
};
export type CatalogPendingEventItem = {
billableEventId: string;
patientDisplayName: string;
patientNumber: string;
quantity: string;
pendingReason: string;
occurredAt: string;
};
export type CatalogServiceItem = {
billingServiceId: string;
code: string;
name: string;
category: string;
status: string;
amount: string | null;
resolvedScope: string;
currency: string;
effectiveFrom: string | null;
};
export type CatalogTariffHistoryItem = {
tariffId: string;
resolvedScope: string;
amount: string;
currency: string;
effectiveFrom: string;
effectiveTo: string | null;
status: string;
current: boolean;
};
}
declare namespace App.Domains.Clinical.Domain.Enum {
export type ClinicalNoteAmendmentType = 'AMENDMENT' | 'ADDENDUM' | 'CORRECTION';
export type ClinicalNoteStatus = 'DRAFT' | 'SIGNED' | 'CANCELLED';
export type ClinicalProcedureStatus = 'REQUESTED' | 'AUTHORIZED' | 'READY' | 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED' | 'ABORTED' | 'DECLINED';
}
declare namespace App.Domains.Encounter.Domain.Enum {
export type EncounterDisposition = 'DISCHARGED' | 'ADMITTED' | 'REFERRED' | 'TRANSFERRED' | 'LEFT' | 'FOLLOW_UP' | 'DECEASED' | 'OTHER';
export type EncounterStatus = 'PLANNED' | 'ARRIVED' | 'ACTIVE' | 'COMPLETED' | 'CANCELLED' | 'LEFT_WITHOUT_BEING_SEEN' | 'ABANDONED' | 'TRANSFERRED' | 'REFERRED';
export type EncounterType = 'OUTPATIENT' | 'EMERGENCY' | 'INPATIENT' | 'MATERNITY' | 'DIAGNOSTIC' | 'PROCEDURE' | 'DAY_CARE' | 'FOLLOW_UP' | 'TELEHEALTH' | 'REFERRAL' | 'OTHER';
}
declare namespace App.Domains.Encounter.Presentation.Http.DTO {
export type ReceptionAppointmentItem = {
id: string;
facilityId: string;
appointmentNumber: string;
slotId: string | null;
patientId: string;
resourceId: string;
appointmentTypeId: string;
scheduledStart: string;
scheduledEnd: string;
status: string;
reason: string | null;
patient: { patientId: string; patientNumber: string; displayName: string; dateOfBirth: string | null; sex: string; status: string; isProvisional: boolean; } | null;
appointmentTypeName: string | null;
resourceName: string | null;
};
export type ReceptionCheckInOutcome = {
encounterNumber: string;
queuePlacement: { status: 'NOT_REQUESTED' | 'PLACED' | 'UNCONFIRMED'; queueId: string | null; ticketNumber: string | null; };
};
export type ReceptionDashboardProps = {
facilityId: string;
date: string;
timezone: string;
encounters: Array<App.Domains.Encounter.Presentation.Http.DTO.ReceptionEncounterItem>;
appointments: Array<App.Domains.Encounter.Presentation.Http.DTO.ReceptionAppointmentItem>;
metrics: App.Domains.Encounter.Presentation.Http.DTO.ReceptionMetrics;
availableQueues: Array<any>;
searchResults: Array<any> | null;
referrals: Array<App.Domains.Encounter.Presentation.Http.DTO.ReceptionReferralItem>;
rosterStatus: string;
rosterFilter: string;
searchError: string | null;
weekLabel: string | null;
days: Array<any> | null;
restricted: Array<any>;
};
export type ReceptionEncounterItem = {
encounterId: string;
encounterNumber: string;
patientId: string;
facilityId: string;
encounterType: string;
status: string;
disposition: string | null;
departmentId: string | null;
unitId: string | null;
arrivedAt: string | null;
startedAt: string | null;
admittedAt: string | null;
completedAt: string | null;
createdSource: string | null;
patient: { patientId: string; patientNumber: string; displayName: string; dateOfBirth: string | null; sex: string; status: string; isProvisional: boolean; } | null;
queueStatus: string | null;
queueName: string | null;
ticketNumber: string | null;
vitalsRecorded: boolean | null;
triageRecorded: boolean | null;
};
export type ReceptionMetrics = {
totalEncounters: number;
waitingEncounters: number;
inServiceEncounters: number;
inServicePatients: number | null;
activeAttendanceEncounters: number;
completedEncounters: number;
totalAppointments: number;
expectedAppointments: number;
arrivedAppointments: number;
inServiceAppointments: number;
completedAppointments: number;
};
export type ReceptionReferralItem = {
referralId: string;
patientId: string;
priority: string;
status: string;
specialtyRequested: string | null;
targetType: string;
createdAt: string | null;
patient: { patientId: string; patientNumber: string; displayName: string; dateOfBirth: string | null; sex: string; status: string; isProvisional: boolean; } | null;
};
}
declare namespace App.Domains.HR.Domain.ValueObject {
export type AssignmentScope = 'FACILITY' | 'DEPARTMENT' | 'UNIT';
export type AssignmentStatus = 'ACTIVE' | 'ENDED';
export type AttendanceDirection = 'IN' | 'OUT' | 'BREAK_OUT' | 'BREAK_IN';
export type AttendanceExceptionType = 'NONE' | 'MISSING_IN' | 'MISSING_OUT' | 'LATE' | 'EARLY_LEAVE' | 'OVERTIME' | 'DISPUTED';
export type AttendanceSessionStatus = 'OPEN' | 'CLOSED' | 'RECONCILED';
export type AttendanceSource = 'DEVICE' | 'MOBILE' | 'MANUAL' | 'SYSTEM';
export type CadreCategory = 'CLINICAL' | 'NURSING' | 'ALLIED' | 'PHARMACY' | 'LABORATORY' | 'ADMIN' | 'SUPPORT';
export type ContractType = 'PERMANENT' | 'FIXED_TERM' | 'CASUAL' | 'LOCUM' | 'VOLUNTEER' | 'INTERN';
export type EmploymentStatus = 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'ENDED';
export type Gender = 'MALE' | 'FEMALE' | 'OTHER' | 'UNKNOWN';
export type LeaveLedgerKind = 'OPENING' | 'ACCRUAL' | 'TAKEN' | 'ADJUSTMENT' | 'CARRY_OVER' | 'EXPIRY' | 'ENCASHMENT';
export type LeaveRequestStatus = 'REQUESTED' | 'APPROVED' | 'REJECTED' | 'CANCELLED' | 'TAKEN';
export type RegistrationStatus = 'ACTIVE' | 'EXPIRED' | 'LAPSED' | 'SUSPENDED_BY_COUNCIL';
export type RosterEntryStatus = 'DRAFT' | 'PUBLISHED' | 'COMPLETED' | 'ABSENT' | 'SWAPPED' | 'CANCELLED';
export type StaffStatus = 'PROSPECT' | 'ONBOARDING' | 'ACTIVE' | 'SUSPENDED' | 'TERMINATED';
export type VerificationSource = 'MANUAL' | 'COUNCIL_API';
export type WorkforceAvailabilityStatus = 'ON_SHIFT' | 'SCHEDULED_NOT_CLOCKED_IN' | 'ON_LEAVE' | 'OFF_DUTY';
}
declare namespace App.Domains.Identity.Domain.Enum {
export type AssignmentScopeType = 'ORG' | 'FACILITY' | 'DEPARTMENT' | 'UNIT';
export type AssignmentStatus = 'PENDING' | 'ACTIVE' | 'SUSPENDED' | 'EXPIRED' | 'REVOKED';
export type PermissionLayer = 'ACCESS' | 'ACTION';
export type RoleStatus = 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'RETIRED';
}
declare namespace App.Domains.Organization.Domain.Enum {
export type CapabilityState = 'AVAILABLE' | 'ENABLED' | 'SUSPENDED' | 'DISABLED';
export type FacilityStatus = 'ACTIVE' | 'SUSPENDED' | 'CLOSED';
export type OrganizationStatus = 'ACTIVE' | 'SUSPENDED';
export type PolicySource = 'ORG_DEFAULT' | 'FACILITY_OVERRIDE';
export type StructureStatus = 'ACTIVE' | 'INACTIVE' | 'DECOMMISSIONED';
}
declare namespace App.Domains.Patient.Domain.Enum {
export type AddressStatus = 'ACTIVE' | 'INACTIVE';
export type AddressType = 'RESIDENTIAL' | 'POSTAL' | 'OTHER';
export type ContactStatus = 'ACTIVE' | 'INACTIVE';
export type ContactType = 'MOBILE' | 'LANDLINE' | 'EMAIL' | 'OTHER';
export type DobPrecision = 'KNOWN' | 'ESTIMATED' | 'UNKNOWN';
export type DuplicateOutcome = 'BLOCK' | 'REVIEW' | 'ALLOW';
export type IdentifierType = 'NATIONAL_ID' | 'INSURANCE_MEMBER' | 'PASSPORT' | 'OTHER';
export type IdentifierVerificationStatus = 'UNVERIFIED' | 'VERIFIED' | 'EXPIRED' | 'REVOKED';
export type MergeHistoryStatus = 'ACTIVE' | 'REVERSED';
export type PatientStatus = 'ACTIVE' | 'INACTIVE' | 'DECEASED' | 'MERGED';
export type RelationshipRole = 'PARENT' | 'CHILD' | 'SPOUSE' | 'GUARDIAN' | 'CAREGIVER' | 'EMERGENCY_CONTACT' | 'NEXT_OF_KIN';
export type RelationshipStatus = 'ACTIVE' | 'INACTIVE';
export type Sex = 'MALE' | 'FEMALE' | 'UNKNOWN';
}
declare namespace App.Domains.Pharmacy.Presentation.Http.DTO {
export type PharmacyDashboardProps = {
facilityId: string;
canTranscribe: boolean;
canManageOrders: boolean;
canDispense: boolean;
canReverse: boolean;
canRecordPickup: boolean;
prescriptions: Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxPrescriptionItem>;
prescriptionStatus: string | null;
orders: Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxOrderItem>;
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
selectedPatientAllergies: Array<any> | null;
view: string | null;
viewPatient: App.Domains.Pharmacy.Presentation.Http.DTO.RxPatientStack | null;
dispensings: Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxDispensingItem>;
items: Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxItemOption>;
locations: Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxLocationOption>;
selectedItemId: string | null;
selectedItemCode: string | null;
selectedItemName: string | null;
selectedItemUom: string | null;
availabilityStatus: string;
stockByLocation: Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxStockEntry>;
searchError: string | null;
};
export type RxDispensingItem = {
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
};
export type RxItemOption = {
itemId: string;
code: string;
name: string;
uom: string;
active: boolean;
itemType: string;
trackBatch: boolean;
};
export type RxLocationOption = {
locationId: string;
code: string;
name: string;
};
export type RxOrderItem = {
pharmacyOrderId: string;
orderNumber: string;
prescriptionId: string;
patientId: string;
patientDisplayName: string;
patientNumber: string;
patientAge: number | null;
patientSex: string;
medicationName: string | null;
status: string;
priority: string;
dispensingCount: number;
occurredAt: string;
dispensings: Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxDispensingItem>;
externalIntakeId: string | null;
};
export type RxPatientResult = {
patientId: string;
patientNumber: string;
displayName: string;
sex: string;
status: string;
isProvisional: boolean;
hasAccessibleFacilityRegistration: boolean;
survivingPatientId: string | null;
};
export type RxPatientStack = {
patientId: string;
displayName: string;
patientNumber: string;
prescriptions: Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxPrescriptionItem>;
orders: Array<App.Domains.Pharmacy.Presentation.Http.DTO.RxOrderItem>;
};
export type RxPrescriptionItem = {
prescriptionId: string;
prescriptionNumber: string;
patientId: string;
patientDisplayName: string;
patientNumber: string;
patientAge: number | null;
patientSex: string;
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
};
export type RxStockEntry = {
locationId: string;
batchId: string | null;
batchNumber: string | null;
expiryDate: string | null;
onHand: string;
reserved: string;
available: string;
uom: string;
eligible: boolean;
};
}
declare namespace App.Domains.Scheduling.Domain.ValueObject {
export type AppointmentStatus = 'REQUESTED' | 'BOOKED' | 'CONFIRMED' | 'ARRIVED' | 'IN_SERVICE' | 'COMPLETED' | 'CANCELLED' | 'RESCHEDULED' | 'NO_SHOW';
export type ResourceType = 'PROVIDER' | 'ROOM' | 'EQUIPMENT';
export type SlotStatus = 'OPEN' | 'HELD' | 'BOOKED' | 'BLOCKED';
export type WaitlistPriority = 'ROUTINE' | 'URGENT' | 'EMERGENCY';
export type WaitlistStatus = 'ACTIVE' | 'OFFERED' | 'BOOKED' | 'EXPIRED' | 'CANCELLED';
}
declare namespace App.Platform.Documents.Domain.Enum {
export type ConfidentialityLevel = 'STANDARD' | 'RESTRICTED';
export type DocumentKind = 'UPLOAD' | 'GENERATED';
export type DocumentStatus = 'PENDING_SCAN' | 'SCANNING' | 'AVAILABLE' | 'QUARANTINED' | 'ON_HOLD' | 'RETAINED' | 'PENDING_DISPOSAL' | 'DISPOSAL_FAILED' | 'DISPOSED';
export type DocumentType = 'INVOICE' | 'RECEIPT' | 'LAB_REPORT' | 'IMAGING_REPORT' | 'DISCHARGE_SUMMARY' | 'PRESCRIPTION' | 'REFERRAL_LETTER' | 'CONSENT' | 'ID_SCAN' | 'PATIENT_PHOTO' | 'STAFF_PHOTO' | 'CLAIM_ATTACHMENT' | 'OTHER';
export type RetentionImposedBy = 'SOURCE_DOMAIN' | 'GOVERNANCE' | 'LEGAL_HOLD';
export type SignatureMethod = 'DRAWN_VECTOR' | 'CRYPTO_SEAL' | 'OTP_ATTESTATION' | 'PKI_CERTIFICATE';
export type SignatureVerificationStatus = 'VALID' | 'TAMPERED' | 'VERSION_DISPOSED';
export type SignerType = 'STAFF' | 'PATIENT' | 'EXTERNAL_PARTY';
export type TokenAction = 'METADATA' | 'DOWNLOAD';
}
declare namespace App.Platform.Integration.Domain.Enum {
export type AuthType = 'HMAC_SHA256' | 'BEARER_TOKEN' | 'BASIC_AUTH' | 'API_KEY' | 'NONE';
export type ConnectorDirection = 'INBOUND' | 'OUTBOUND' | 'BIDIRECTIONAL';
export type ConnectorFamily = 'PAYMENT_GATEWAY' | 'INSURANCE_PAYER' | 'MESSAGING_GATEWAY' | 'DIAGNOSTIC_ANALYZER' | 'NATIONAL_REGISTRY' | 'ACCOUNTING_ERP' | 'OTHER';
export type ConnectorStatus = 'DISABLED' | 'SANDBOX' | 'ENABLED' | 'SUSPENDED';
export type CredentialAlgorithm = 'HMAC_SHA256' | 'RSA_2048' | 'ED25519' | 'RAW_TOKEN';
export type CredentialStatus = 'ACTIVE' | 'ROTATING' | 'REVOKED' | 'EXPIRED';
export type DispatchStatus = 'PENDING' | 'DISPATCHED' | 'COMMITTED' | 'REJECTED' | 'FAILED';
export type FileImportStatus = 'RECEIVED' | 'PARSING' | 'TRANSLATING' | 'DISPATCHED' | 'RECONCILED' | 'PARTIAL' | 'FAILED';
export type ImportDomainOutcome = 'PENDING' | 'COMMITTED' | 'REJECTED';
export type InboundMessageStatus = 'RECEIVED' | 'AUTHENTICATED' | 'TRANSLATED' | 'DISPATCHED' | 'ACK' | 'REJECTED' | 'DEAD_LETTER';
export type MappingSource = 'INBOUND_OBSERVED' | 'MANUAL' | 'DOMAIN_PROVIDED' | 'VERIFIED';
export type OutboundMessageStatus = 'QUEUED' | 'SENDING' | 'SENT' | 'ACK' | 'FAILED' | 'DEAD_LETTER';
export type PayloadSensitivity = 'STANDARD' | 'REDACTED' | 'MINIMIZED' | 'ENCRYPTED';
export type VerificationStatus = 'UNVERIFIED' | 'VERIFIED' | 'DISPUTED';
}
declare namespace App.Platform.Notifications.Domain.Model {
export type DeliveryAttemptStatus = 'QUEUED' | 'SENT' | 'DELIVERED' | 'FAILED' | 'SUPPRESSED';
export type NotificationCategory = 'TRANSACTIONAL' | 'REMINDER' | 'OUTREACH' | 'SYSTEM';
export type NotificationChannel = 'SMS' | 'EMAIL' | 'IN_APP';
export type NotificationPriority = 'ROUTINE' | 'URGENT' | 'EMERGENCY';
export type NotificationStatus = 'ACCEPTED' | 'RENDERED' | 'DISPATCHED' | 'DELIVERED' | 'FAILED' | 'SUPPRESSED';
export type RecipientType = 'PATIENT' | 'STAFF' | 'USER';
export type SuppressionReason = 'OPT_OUT' | 'QUIET_HOURS' | 'INVALID_ADDRESS' | 'NO_CONSENT' | 'NO_USER_ACCOUNT' | 'RATE_LIMITED' | 'DUPLICATE';
}
declare namespace App.Platform.Queueing.Domain.Enum {
export type HoldReason = 'FINANCIAL_HOLD' | 'CLINICAL_HOLD' | 'ADMINISTRATIVE_HOLD';
export type OrderType = 'LAB_ORDER' | 'PRESCRIPTION' | 'RADIOLOGY_REQUEST' | 'PROCEDURE_BOOKING';
export type QueueOperatingStatus = 'ACTIVE' | 'PAUSED' | 'CLOSED';
export type QueuePriority = 'ROUTINE' | 'PRIORITY' | 'URGENT' | 'EMERGENCY';
export type QueueStatus = 'WAITING' | 'CALLED' | 'IN_SERVICE' | 'HOLD' | 'COMPLETED' | 'NO_RESPONSE' | 'SKIPPED' | 'LEFT_WITHOUT_BEING_SEEN' | 'EXPIRED';
export type ServiceCapability = 'TRIAGE' | 'CONSULTATION' | 'PHARMACY' | 'LABORATORY' | 'RADIOLOGY' | 'CASHIER' | 'PROCEDURE';
}
declare namespace App.Platform.Reporting.Domain.ValueObject {
export type ProjectionStreamStatus = 'IDLE' | 'BUILDING' | 'LIVE' | 'REBUILDING';
export type ReportCategory = 'CLINICAL' | 'FINANCIAL' | 'OPERATIONAL' | 'STATUTORY' | 'CUSTOM';
export type ReportRunStatus = 'REQUESTED' | 'RUNNING' | 'COMPLETED' | 'FAILED';
export type StatutoryFramework = 'HMIS_MTUHA' | 'DHIS2' | 'SURVEILLANCE';
export type StatutoryReturnStatus = 'DRAFT' | 'GENERATED' | 'REVIEWED' | 'SUBMITTED' | 'ACKNOWLEDGED' | 'SUPERSEDED';
}
declare namespace App.Shared.Idempotency {
export type ClaimStatus = 'claimed' | 'completed' | 'in_progress';
}
declare namespace App.Shared.Tenancy {
export type SystemOperation = 'migration' | 'ops_console';
}
