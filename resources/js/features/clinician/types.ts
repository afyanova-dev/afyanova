import type {
  NurseStationEntry,
  NurseStationPatient,
  NurseStationQueueNavigation,
  NurseStationTriage,
  NurseStationVitals,
} from '@/features/nurse-station/types';

export type ClinicianEntry = NurseStationEntry;

export interface ClinicianNoteDraft {
  values: {
    note_type?: string;
    title?: string;
    body?: string;
  };
  savedAt: string;
  expiresAt: string;
}

export interface ClinicianDiagnosisDraft {
  values: {
    description?: string;
    code?: string;
    code_system?: string;
    clinical_status?: string;
  };
  savedAt: string;
  expiresAt: string;
}

export interface ClinicianPlanDraft {
  values: {
    plan_type?: string;
    clinical_intent?: string;
    goals?: string;
    planned_actions?: string;
    instructions?: string;
    follow_up?: string;
  };
  savedAt: string;
  expiresAt: string;
}

export interface ClinicianShowProps {
  entry: Pick<ClinicianEntry, 'id' | 'ticketNumber' | 'queue' | 'priority' | 'status'>;
  callRooms: Array<{
    id: string;
    name: string;
    code: string;
  }>;
  canCall: boolean;
  canStartService: boolean;
  queueNavigation: NurseStationQueueNavigation;
  patient: NurseStationPatient;
  encounter: {
    encounterId: string;
    status: string;
    type: string;
  };
  triage: NurseStationTriage | null;
  vitals: NurseStationVitals[];
  procedureDefinitions: Array<{
    definitionId: string;
    code: string;
    name: string;
    queueConfigured: boolean;
  }>;
  labTestCatalog: Array<{
    definitionId: string;
    code: string;
    name: string;
    category: string | null;
    specimenType: string | null;
  }>;
  labPanelCatalog: Array<{
    panelId: string;
    code: string;
    name: string;
    category: string | null;
  }>;
  procedures: Array<{
    procedureId: string;
    procedureNumber: string;
    definitionId: string;
    status: string;
    authorizationBasis: string | null;
    authorizationProtocolReference: string | null;
    indication: string | null;
  }>;
  prescribingDecisions: Array<{
    prescribingDecisionId: string;
    medicationName: string;
    dose: string | null;
    route: string | null;
    frequency: string | null;
    duration: string | null;
    quantity: string | null;
    quantityValue: string | null;
    quantityUnit: string | null;
    dosingInstruction: string | null;
    status: string;
    occurredAt: string;
  }>;
  medicationCatalog: Array<{
    id: string;
    code: string;
    name: string;
    unitOfMeasure: string;
    category: string | null;
  }>;
  diagnosisCatalog: Array<{
    id: string;
    code: string;
    codeSystem: string;
    name: string;
    aliases: string;
    category: string;
  }>;
  notes: Array<{
    clinicalNoteId: string;
    authoredContext?: string | null;
    noteType: string;
    title: string | null;
    body: string;
    status: string;
    occurredAt: string;
    recordedAt: string;
    signedAt: string | null;
    amendmentCount: number;
  }>;
  diagnoses: Array<{
    diagnosisId: string;
    description: string;
    code: string | null;
    codeSystem: string | null;
    clinicalStatus: string;
    occurredAt: string;
    recordedAt: string;
  }>;
  plans: Array<{
    planId: string;
    planType: string;
    clinicalIntent: string;
    status: string;
    followUp: string | null;
    occurredAt: string;
  }>;
  allergies: Array<{
    allergyId: string;
    substanceName: string;
    reactionDescription: string | null;
    severity: string | null;
    verificationStatus: string;
    clinicalStatus: string;
  }>;
  problems: Array<{
    problemId: string;
    description: string;
    code: string | null;
    codeSystem: string | null;
    status: string;
  }>;
  medications: Array<{
    medicationId: string;
    medicationName: string | null;
    dose: string | null;
    route: string | null;
    frequency: string | null;
    status: string;
    occurredAt: string;
  }>;
  drafts: {
    note: ClinicianNoteDraft | null;
    diagnosis: ClinicianDiagnosisDraft | null;
    plan: ClinicianPlanDraft | null;
  };
  canReadNotes: boolean;
  canSignNotes: boolean;
  canAmendNotes: boolean;
  canReadDiagnoses: boolean;
  canReadPlans: boolean;
  canReadAllergies: boolean;
  canReadProblems: boolean;
  canReadMedications: boolean;
  canReadPrescribing: boolean;
  canRecordPrescribing: boolean;
  canRecordLabOrders: boolean;
  canRequestProcedure: boolean;
  canRecordNote: boolean;
  canRecordDiagnosis: boolean;
  canRecordPlan: boolean;
  canStartEncounter: boolean;
  canChangeDisposition: boolean;
  canCompleteEncounter: boolean;
}
