export interface NurseStationEntry {
  id: string;
  ticketNumber: string;
  patientName: string;
  patientNumber: string;
  queue: string;
  priority: string;
  status: string;
  waitMinutes: number;
}

export interface NurseStationVitals {
  temperature: number | null;
  heartRate: number | null;
  respiratoryRate: number | null;
  systolicBp: number | null;
  diastolicBp: number | null;
  oxygenSaturation: number | null;
  weight: number | null;
  height: number | null;
  painScore: number | null;
  occurredAt: string;
  authoredContext: string | null;
}

export interface NurseStationPatient {
  patientId: string;
  patientNumber: string;
  displayName: string;
  dateOfBirth: string | null;
  sex: string;
  status: string;
  isProvisional: boolean;
  photoUrl: string | null;
}

export interface NurseStationEncounter {
  encounterId: string;
  status: string;
  type: string;
}

export interface NurseStationTriage {
  chiefComplaint: string;
  priority: string;
  acuity: string | null;
  consciousness: string | null;
  occurredAt: string;
}

export interface NurseStationConsultationQueue {
  id: string;
  name: string;
}

export interface NurseStationQueueNeighbor {
  id: string;
  ticketNumber: string;
}

export interface NurseStationQueueNavigation {
  position: number;
  total: number;
  previous: NurseStationQueueNeighbor | null;
  next: NurseStationQueueNeighbor | null;
}

export interface NurseStationShowProps {
  entry: Pick<NurseStationEntry, 'id' | 'ticketNumber' | 'queue' | 'priority' | 'status'>;
  queueNavigation: NurseStationQueueNavigation;
  patient: NurseStationPatient;
  encounter: NurseStationEncounter;
  vitals: NurseStationVitals[];
  triage: NurseStationTriage | null;
  canRecordVitals: boolean;
  canRecordTriage: boolean;
  canStartService: boolean;
  consultationQueues: NurseStationConsultationQueue[];
  canHandoff: boolean;
}
