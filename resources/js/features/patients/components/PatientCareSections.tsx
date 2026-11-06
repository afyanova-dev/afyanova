import type { ReactNode } from 'react';
import { formatClinicalDateTime } from '@/lib/formatting';
import type {
  PatientChartProgram,
  PatientChartReferral,
  PatientChartVisit,
} from '@/features/patients/types/chart';
import { humanizeClinicalValue } from './patientChartData';
import { PatientRecordTable, PatientSectionState, type PatientSectionAccessState } from './PatientRecordTable';

function date(value: string | null | undefined): string {
  return formatClinicalDateTime(value) ?? '—';
}

export function PatientVisitsSection({
  visits,
  state,
}: {
  visits: PatientChartVisit[];
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  return (
    <PatientRecordTable
      title="Visits"
      ariaLabel="Patient visits"
      searchLabel="Search visits"
      headers={[
        { key: 'encounter', header: 'Encounter' },
        { key: 'type', header: 'Type' },
        { key: 'department', header: 'Department' },
        { key: 'unit', header: 'Unit' },
        { key: 'arrived', header: 'Arrived' },
        { key: 'started', header: 'Started' },
        { key: 'completed', header: 'Completed' },
        { key: 'status', header: 'Status' },
      ]}
      rows={visits.map((visit) => ({
        id: visit.id,
        cells: [
          visit.number,
          humanizeClinicalValue(visit.encounterType),
          visit.departmentName ?? '—',
          visit.unitName ?? '—',
          date(visit.arrivedAt),
          date(visit.startedAt),
          date(visit.completedAt),
          visit.status,
        ],
      }))}
      statusHeaders={['status']}
      emptyTitle="No visits recorded"
      emptyDescription="No encounters or visits are recorded for this patient."
    />
  );
}

export function PatientProgramsSection({
  programs,
  state,
}: {
  programs: PatientChartProgram[];
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  return (
    <PatientRecordTable
      title="Care programs"
      ariaLabel="Patient care programs"
      searchLabel="Search programs"
      headers={[
        { key: 'program', header: 'Program' },
        { key: 'subtype', header: 'Subtype' },
        { key: 'opened', header: 'Opened' },
        { key: 'closed', header: 'Closed' },
        { key: 'status', header: 'Status' },
      ]}
      rows={programs.map((program) => ({
        id: program.id,
        cells: [
          humanizeClinicalValue(program.type),
          program.subtype ? humanizeClinicalValue(program.subtype) : '—',
          date(program.openedAt),
          date(program.closedAt),
          program.status,
        ],
      }))}
      statusHeaders={['status']}
      emptyTitle="No care programs"
      emptyDescription="No care programs or episodes are recorded for this patient."
    />
  );
}

export function PatientReferralsSection({
  referrals,
  state,
}: {
  referrals: PatientChartReferral[];
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  return (
    <PatientRecordTable
      title="Referrals"
      ariaLabel="Patient referrals"
      searchLabel="Search referrals"
      headers={[
        { key: 'direction', header: 'Direction' },
        { key: 'specialty', header: 'Specialty' },
        { key: 'priority', header: 'Priority' },
        { key: 'status', header: 'Status' },
      ]}
      rows={referrals.map((referral) => ({
        id: referral.id,
        cells: [
          humanizeClinicalValue(referral.direction),
          referral.specialty ?? '—',
          humanizeClinicalValue(referral.priority),
          referral.status,
        ],
      }))}
      statusHeaders={['priority', 'status']}
      emptyTitle="No referrals"
      emptyDescription="No inbound or outbound referrals are recorded for this patient."
    />
  );
}
