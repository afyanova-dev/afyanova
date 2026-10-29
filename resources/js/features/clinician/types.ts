import type {
  NurseStationEntry,
  NurseStationPatient,
  NurseStationQueueNavigation,
  NurseStationTriage,
  NurseStationVitals,
} from '@/features/nurse-station/types';

export type ClinicianEntry = NurseStationEntry;

export interface ClinicianShowProps {
  entry: Pick<ClinicianEntry, 'id' | 'ticketNumber' | 'queue' | 'priority' | 'status'>;
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
  procedures: Array<{
    procedureId: string;
    procedureNumber: string;
    definitionId: string;
    status: string;
    authorizationBasis: string | null;
    authorizationProtocolReference: string | null;
    indication: string | null;
  }>;
  canRequestProcedure: boolean;
  canRecordNote: boolean;
  canRecordDiagnosis: boolean;
  canRecordPlan: boolean;
  canStartEncounter: boolean;
  canChangeDisposition: boolean;
  canCompleteEncounter: boolean;
}
