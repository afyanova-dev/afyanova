/**
 * Front Desk (reception) page props — mirrors
 * `ReceptionController` (`Reception/Index`) 1:1. No invented fields:
 * denied sections compose empty with a `restricted` flag instead of rows.
 */
export interface ReceptionPatientIdentity {
  patientId: string;
  patientNumber: string;
  displayName: string;
  dateOfBirth: string | null;
  sex: string;
  status: string;
  isProvisional: boolean;
}

export interface ReceptionEncounterItem {
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
  patient: ReceptionPatientIdentity | null;
  queueStatus: string | null;
  queueName: string | null;
  ticketNumber: string | null;
  vitalsRecorded: boolean | null;
  triageRecorded: boolean | null;
}

export interface ReceptionAppointmentItem {
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
  patient: ReceptionPatientIdentity | null;
  appointmentTypeName: string | null;
  resourceName: string | null;
}

export interface ReceptionMetrics {
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
}

export interface ReceptionQueueOption {
  id: string;
  departmentId: string | null;
  name: string;
  code: string;
  serviceCapability: string | null;
  roomNames?: string[];
}

export interface ReceptionProtocolProcedure {
  definitionId: string;
  code: string;
  name: string;
  queueId: string;
  queueName: string;
  protocolReference: string;
  protocolVersion: string;
}

export interface ReceptionReferralItem {
  referralId: string;
  patientId: string;
  priority: string;
  status: string;
  specialtyRequested: string | null;
  targetType: string;
  createdAt: string | null;
  patient: ReceptionPatientIdentity | null;
}

export interface ReceptionWeekDay {
  dayOfWeek: string;
  date: string;
  label: string;
  isToday: boolean;
  isSelected: boolean;
}

export interface ReceptionSearchResult {
  patientId: string;
  patientNumber: string;
  displayName: string;
  sex: string;
  status: string;
  isProvisional: boolean;
  hasAccessibleFacilityRegistration: boolean;
  survivingPatientId: string | null;
}

export interface ReceptionPreselectedPatient {
  patientId: string;
  patientNumber: string;
  displayName: string;
  sex: string;
  status: string;
  isProvisional: boolean;
  hasAccessibleFacilityRegistration: boolean;
  survivingPatientId: string | null;
}

export interface ReceptionPageProps {
  facilityId: string;
  date: string;
  timezone: string;
  encounters: ReceptionEncounterItem[];
  appointments: ReceptionAppointmentItem[];
  metrics: ReceptionMetrics;
  availableQueues: ReceptionQueueOption[];
  protocolProcedures?: ReceptionProtocolProcedure[];
  searchResults: ReceptionSearchResult[] | null;
  searchError: string | null;
  preselectedPatient: ReceptionPreselectedPatient | null;
  activeEncounter: ReceptionEncounterItem | null;
  referrals: ReceptionReferralItem[];
  rosterStatus: string;
  rosterFilter: string;
  weekLabel: string;
  days: ReceptionWeekDay[];
  restricted: string[];
}
