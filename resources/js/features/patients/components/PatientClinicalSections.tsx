import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { routes } from '@/app/routes';
import { formatClinicalDateTime } from '@/lib/formatting';
import type {
  PatientChartAllergy,
  PatientChartCondition,
  PatientChartMedication,
  PatientChartPlan,
  PatientChartProcedure,
  PatientChartVital,
} from '@/features/patients/types/chart';
import { humanizeClinicalValue } from './patientChartData';
import { PatientRecordTable, PatientSectionState, type PatientSectionAccessState } from './PatientRecordTable';

function date(value: string | null | undefined): string {
  return formatClinicalDateTime(value) ?? '—';
}

export function PatientAllergiesSection({
  allergies,
  state,
}: {
  allergies: PatientChartAllergy[];
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  return (
    <PatientRecordTable
      title="Allergies"
      ariaLabel="Patient allergies"
      searchLabel="Search allergies"
      headers={[
        { key: 'substance', header: 'Substance' },
        { key: 'category', header: 'Category' },
        { key: 'severity', header: 'Severity' },
        { key: 'reaction', header: 'Reaction' },
        { key: 'verification', header: 'Verification' },
        { key: 'status', header: 'Status' },
        { key: 'onset', header: 'Onset' },
        { key: 'resolved', header: 'Resolved' },
        { key: 'recorded', header: 'Recorded' },
      ]}
      rows={allergies.map((allergy) => ({
        id: allergy.id,
        cells: [
          allergy.substance,
          allergy.category ? humanizeClinicalValue(allergy.category) : '—',
          allergy.severity ?? '—',
          allergy.reaction ?? '—',
          allergy.verification ?? '—',
          allergy.status ?? '—',
          date(allergy.onset),
          date(allergy.resolvedAt),
          date(allergy.recordedAt),
        ],
      }))}
      statusHeaders={['severity', 'verification', 'status']}
      emptyTitle="No allergies recorded"
      emptyDescription="No allergy history has been recorded for this patient."
    />
  );
}

export function PatientConditionsSection({
  conditions,
  state,
}: {
  conditions: PatientChartCondition[];
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  return (
    <PatientRecordTable
      title="Problems"
      ariaLabel="Patient problems"
      searchLabel="Search problems"
      headers={[
        { key: 'problem', header: 'Problem' },
        { key: 'code', header: 'Code' },
        { key: 'codeSystem', header: 'Code system' },
        { key: 'onset', header: 'Onset' },
        { key: 'recorded', header: 'Recorded' },
        { key: 'resolved', header: 'Resolved' },
        { key: 'status', header: 'Status' },
      ]}
      rows={conditions.map((condition) => ({
        id: condition.id,
        cells: [
          condition.description,
          condition.code ?? '—',
          condition.codeSystem ?? '—',
          date(condition.onset),
          date(condition.recordedAt),
          date(condition.resolvedAt),
          condition.status,
        ],
      }))}
      statusHeaders={['status']}
      emptyTitle="No problems recorded"
      emptyDescription="No active or historical problems are recorded for this patient."
    />
  );
}

export function PatientMedicationsSection({
  medications,
  activeFacilityId,
  state,
}: {
  medications: PatientChartMedication[];
  activeFacilityId: string | null;
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;
  const byId = new Map(medications.map((medication) => [medication.id, medication]));

  return (
    <PatientRecordTable
      title="Medications"
      ariaLabel="Patient medications"
      searchLabel="Search medications"
      headers={[
        { key: 'medication', header: 'Medication' },
        { key: 'dose', header: 'Dose' },
        { key: 'frequency', header: 'Frequency' },
        { key: 'route', header: 'Route' },
        { key: 'duration', header: 'Duration' },
        { key: 'instructions', header: 'Instructions' },
        { key: 'prescriber', header: 'Prescriber' },
        { key: 'status', header: 'Status' },
        { key: 'recorded', header: 'Recorded' },
        { key: 'actions', header: 'Actions' },
      ]}
      rows={medications.map((medication) => ({
        id: medication.id,
        cells: [
          medication.medication ?? medication.number,
          medication.dose ?? '—',
          medication.frequency ?? '—',
          medication.route ?? '—',
          medication.duration ?? '—',
          medication.instructions ?? '—',
          medication.prescriberName ?? '—',
          medication.status,
          date(medication.recordedAt),
          '',
        ],
      }))}
      statusHeaders={['status']}
      emptyTitle="No medications recorded"
      emptyDescription="No prescriptions or medication history are recorded for this patient."
      renderCell={(cell, rowId) => {
        if (cell.info.header !== 'actions') return cell.value;

        const medication = byId.get(rowId);
        if (!medication) return null;
        if (activeFacilityId !== null && medication.facilityId === activeFacilityId) {
          return (
            <Link href={`${routes.workspaces.pharmacy}?view=prescriptions&rx_id=${encodeURIComponent(medication.id)}`}>
              Open prescription in Pharmacy
            </Link>
          );
        }

        return <span className="patient-chart__table-note">This prescription belongs to another facility.</span>;
      }}
    />
  );
}

export function PatientVitalsSection({
  vitals,
  state,
}: {
  vitals: PatientChartVital[];
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  const number = (value: number | null, unit: string): string => (value === null ? '—' : `${value} ${unit}`);
  const bloodPressure = (vital: PatientChartVital): string => (
    vital.systolicBp === null || vital.diastolicBp === null
      ? '—'
      : `${vital.systolicBp}/${vital.diastolicBp}`
  );

  return (
    <PatientRecordTable
      title="Vitals & biometrics"
      ariaLabel="Patient vitals and biometrics"
      searchLabel="Search vitals"
      headers={[
        { key: 'taken', header: 'Taken' },
        { key: 'recorded', header: 'Recorded' },
        { key: 'temperature', header: 'Temp' },
        { key: 'heartRate', header: 'HR' },
        { key: 'respiratoryRate', header: 'RR' },
        { key: 'bloodPressure', header: 'BP' },
        { key: 'oxygenSaturation', header: 'SpO₂' },
        { key: 'painScore', header: 'Pain' },
        { key: 'weight', header: 'Weight' },
        { key: 'height', header: 'Height' },
      ]}
      rows={vitals.map((vital) => ({
        id: vital.id,
        cells: [
          date(vital.occurredAt),
          date(vital.recordedAt),
          number(vital.temperature, '°C'),
          number(vital.heartRate, 'bpm'),
          number(vital.respiratoryRate, '/min'),
          bloodPressure(vital),
          number(vital.oxygenSaturation, '%'),
          vital.painScore === null || vital.painScore === undefined ? '—' : `${vital.painScore}/10`,
          number(vital.weight, 'kg'),
          number(vital.height, 'cm'),
        ],
      }))}
      emptyTitle="No vitals recorded"
      emptyDescription="No vital signs or biometric observations are recorded for this patient."
    />
  );
}

export function PatientProceduresSection({
  procedures,
  state,
}: {
  procedures: PatientChartProcedure[];
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  return (
    <PatientRecordTable
      title="Procedures"
      ariaLabel="Patient procedures"
      searchLabel="Search procedures"
      headers={[
        { key: 'number', header: 'Procedure' },
        { key: 'category', header: 'Category' },
        { key: 'outcome', header: 'Outcome' },
        { key: 'completed', header: 'Completed' },
        { key: 'status', header: 'Status' },
      ]}
      rows={procedures.map((procedure) => ({
        id: procedure.id,
        cells: [
          procedure.number,
          procedure.category ? humanizeClinicalValue(procedure.category) : '—',
          procedure.outcome ? humanizeClinicalValue(procedure.outcome) : '—',
          date(procedure.completedAt),
          procedure.status,
        ],
      }))}
      statusHeaders={['status']}
      emptyTitle="No procedures recorded"
      emptyDescription="No clinical procedures are recorded for this patient."
    />
  );
}

export function PatientPlansSection({
  plans,
  state,
}: {
  plans: PatientChartPlan[];
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  return (
    <PatientRecordTable
      title="Care plans"
      ariaLabel="Patient care plans"
      searchLabel="Search care plans"
      headers={[
        { key: 'type', header: 'Plan' },
        { key: 'intent', header: 'Intent' },
        { key: 'followUp', header: 'Follow-up' },
        { key: 'status', header: 'Status' },
      ]}
      rows={plans.map((plan) => ({
        id: plan.id,
        cells: [
          humanizeClinicalValue(plan.type),
          humanizeClinicalValue(plan.intent),
          date(plan.followUp),
          plan.status,
        ],
      }))}
      statusHeaders={['status']}
      emptyTitle="No care plans recorded"
      emptyDescription="No care plans or follow-up plans are recorded for this patient."
    />
  );
}
