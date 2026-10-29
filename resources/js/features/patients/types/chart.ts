export interface PatientChartPatient {
  patientId: string;
  patientNumber: string;
  displayName: string;
  givenName: string;
  familyName: string;
  middleName: string | null;
  dateOfBirth: string | null;
  dobPrecision: string;
  sex: string;
  status: string;
  isProvisional: boolean;
  primaryPhone: string | null;
  email: string | null;
  registrationSource: string | null;
  registeredAt: string | null;
  hasAccessibleFacilityRegistration: boolean;
  facilityRegistrationCount: number;
  survivingPatientId: string | null;
  identifiers: PatientChartIdentifier[];
  addresses: PatientChartAddress[];
  relationships: PatientChartRelationship[];
  auditHistory: PatientChartAuditEntry[];
}

export interface PatientChartIdentifier {
  id: string;
  type: string;
  value: string;
  isVerified: boolean;
}

export interface PatientChartAddress {
  id: string;
  type: string;
  region: string | null;
  district: string | null;
  ward: string | null;
  streetOrVillage: string | null;
  postalCode: string | null;
}

export interface PatientChartRelationship {
  id: string;
  role: string;
  name: string;
  phone: string | null;
  label: string | null;
}

export interface PatientChartAuditEntry {
  action: string;
  actorName: string | null;
  reason: string | null;
  changedFields: string[];
  changedAt: string;
}

export interface ActivePatientEncounter {
  encounterId: string;
  encounterNumber: string;
  encounterType: string;
  status: string;
  departmentId: string | null;
  unitId: string | null;
  arrivedAt: string | null;
  startedAt: string | null;
}

export interface PatientChartAllergy {
  id: string;
  substance: string;
  severity: string | null;
  status: string | null;
  verification: string | null;
  reaction: string | null;
  onset: string | null;
}

export interface PatientChartCondition {
  id: string;
  description: string;
  code: string | null;
  status: string;
  onset: string | null;
}

export interface PatientChartProcedure {
  id: string;
  number: string;
  category: string | null;
  status: string;
  outcome: string | null;
  completedAt: string | null;
}

export interface PatientChartPlan {
  id: string;
  type: string;
  intent: string;
  status: string;
  followUp: string | null;
}

export interface PatientChartVital {
  id: string;
  temperature: number | null;
  heartRate: number | null;
  respiratoryRate: number | null;
  systolicBp: number | null;
  diastolicBp: number | null;
  oxygenSaturation: number | null;
  weight: number | null;
  height: number | null;
  occurredAt: string;
}

export interface PatientChartMedication {
  id: string;
  facilityId: string;
  number: string;
  medication: string | null;
  dose: string | null;
  frequency: string | null;
  status: string;
  recordedAt: string;
}

export interface PatientChartLabOrder {
  orderId: string;
  orderNumber: string;
  priority: string;
  status: string;
  createdAt: string;
  items: PatientChartLabItem[];
}

export interface PatientChartLabItem {
  testCode: string;
  testName: string;
  status: string;
}

export interface PatientChartLabResult {
  parameter: string;
  value: number | string | null;
  unit: string | null;
  flag: string | null;
}

export interface PatientChartImagingOrder {
  orderId: string;
  orderNumber: string;
  priority: string;
  status: string;
  createdAt: string;
  items: PatientChartImagingItem[];
}

export interface PatientChartImagingItem {
  serviceCode: string | null;
  serviceName: string | null;
  status: string;
}

export interface PatientChartAppointment {
  id: string;
  number: string;
  resourceId: string;
  appointmentTypeId: string;
  appointmentTypeName: string | null;
  resourceName: string | null;
  start: string;
  end: string;
  status: string;
  reason: string | null;
}

export interface PatientChartVisit {
  id: string;
  number: string;
  encounterType: string;
  status: string;
  disposition: string | null;
  departmentId: string | null;
  departmentName: string | null;
  unitId: string | null;
  unitName: string | null;
  arrivedAt: string | null;
  startedAt: string | null;
  admittedAt: string | null;
  completedAt: string | null;
}

export interface PatientChartProgram {
  id: string;
  type: string;
  subtype: string | null;
  status: string;
  openedAt: string | null;
  closedAt: string | null;
}

export interface PatientChartReferral {
  id: string;
  direction: string;
  specialty: string | null;
  priority: string;
  status: string;
}

export interface PatientChartBilling {
  account: {
    accountNumber: string;
    status: string;
    currency: string;
  } | null;
  outstanding: {
    financialAccountId: string;
    currency: string;
    chargedTotal: string;
    paidTotal: string;
    writeOffTotal: string;
    adjustmentTotal: string;
    settledTotal: string;
    outstanding: string;
    entryCount: number;
  } | null;
  ledger: PatientChartBillingEntry[];
}

export interface PatientChartBillingEntry {
  entryType: string;
  direction: string;
  amount: string;
  currency: string;
  postedAt: string;
}

export interface PatientChartData {
  allergies: PatientChartAllergy[];
  conditions: PatientChartCondition[];
  procedures: PatientChartProcedure[];
  plans: PatientChartPlan[];
  vitals: PatientChartVital[];
  medications: PatientChartMedication[];
  labOrders: PatientChartLabOrder[];
  labResults: Record<string, PatientChartLabResult[]>;
  imagingOrders: PatientChartImagingOrder[];
  appointments: PatientChartAppointment[];
  visits: PatientChartVisit[];
  programs: PatientChartProgram[];
  referrals: PatientChartReferral[];
  billing: PatientChartBilling;
  unavailable: string[];
  restricted: string[];
}

export interface PatientChartProps {
  patient: PatientChartPatient;
  activeEncounter: ActivePatientEncounter | null;
  availableQueues: Array<{
    id: string;
    departmentId: string | null;
    name: string;
    code: string;
    serviceCapability: string | null;
  }>;
  queueEntry: {
    entryId: string;
    queueId: string;
    ticketNumber: string;
    priority: string;
    status: string;
  } | null;
  photoUrl: string | null;
  chart: PatientChartData;
}
