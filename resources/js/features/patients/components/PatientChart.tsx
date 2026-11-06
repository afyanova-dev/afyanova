import { usePage } from '@inertiajs/react';
import { useCallback, useState, type ReactNode } from 'react';
import { DiscardChangesModal, useWorkspaceGuard } from '@/components/workspace';
import {
  AppointmentDetailsPanel,
  type AppointmentDetails,
} from '@/features/appointments/components/AppointmentDetailsPanel';
import {
  CancelAppointmentDialog,
  RescheduleAppointmentDialog,
  type ActionableAppointment,
} from '@/features/appointments/components/AppointmentLifecycleButtons';
import { BookAppointmentPanel } from '@/features/appointments/components/BookAppointmentPanel';
import {
  CheckInAction,
  type CheckInTarget,
} from '@/features/front-desk/components/CheckInAction';
import { PatientBanner } from '@/features/patients/components/PatientBanner';
import { PatientActionsMenu } from '@/features/patients/components/PatientActionsMenu';
import { PatientStickyNote } from '@/features/patients/components/PatientStickyNote';
import { PatientSummary } from '@/features/patients/components/PatientSummary';
import { EditDemographicsDialog } from '@/features/patients/forms/EditDemographicsDialog';
import { MarkDeceasedDialog } from '@/features/patients/forms/MarkDeceasedDialog';
import { PatientTimeline } from '@/features/patients/components/PatientTimeline';
import { PatientAppointmentsSection } from '@/features/patients/components/PatientAppointmentsSection';
import {
  PatientAllergiesSection,
  PatientConditionsSection,
  PatientMedicationsSection,
  PatientPlansSection,
  PatientProceduresSection,
  PatientVitalsSection,
} from '@/features/patients/components/PatientClinicalSections';
import {
  PatientImagingSection,
  PatientLabResultsSection,
} from '@/features/patients/components/PatientDiagnosticSections';
import {
  PatientProgramsSection,
  PatientReferralsSection,
  PatientVisitsSection,
} from '@/features/patients/components/PatientCareSections';
import { PatientBillingSection } from '@/features/patients/components/PatientBillingSection';
import type { PatientSectionAccessState } from '@/features/patients/components/PatientRecordTable';
import { formatClinicalDateTime } from '@/lib/formatting';
import { permissions, useCan } from '@/lib/authorization';
import { routes } from '@/app/routes';
import type {
  PatientChartAppointment,
  PatientChartData,
  PatientChartProps,
} from '@/features/patients/types/chart';

const sections = [
  { id: 'overview', label: 'Patient Summary', dataKey: null },
  { id: 'timeline', label: 'Timeline', dataKey: null },
  { id: 'conditions', label: 'Problems', dataKey: 'conditions' },
  { id: 'allergies', label: 'Allergies', dataKey: 'allergies' },
  { id: 'medications', label: 'Medications', dataKey: 'medications' },
  { id: 'vitals', label: 'Vitals & biometrics', dataKey: 'vitals' },
  { id: 'plans', label: 'Care plans', dataKey: 'plans' },
  { id: 'procedures', label: 'Procedures', dataKey: 'procedures' },
  { id: 'lab-results', label: 'Lab results', dataKey: 'results' },
  { id: 'imaging', label: 'Imaging', dataKey: 'orders' },
  { id: 'appointments', label: 'Appointments', dataKey: 'appointments' },
  { id: 'visits', label: 'Visits', dataKey: 'visits' },
  { id: 'programs', label: 'Care programs', dataKey: 'programs' },
  { id: 'referrals', label: 'Referrals', dataKey: 'referrals' },
  { id: 'billing-history', label: 'Billing', dataKey: 'billing-history' },
] as const;

type SectionId = (typeof sections)[number]['id'];

function activeSection(url: string, patientId: string): SectionId {
  const path = new URL(url, 'http://afyanova.local').pathname;
  const match = path.match(new RegExp(`^/patient/${escapeRegExp(patientId)}/chart(?:/([^/]+))?/?$`));
  const requested = match?.[1];

  return sections.some((section) => section.id === requested) ? requested as SectionId : 'overview';
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function sectionAccess(chart: PatientChartData, dataKey: string | null): PatientSectionAccessState {
  if (!dataKey) return null;
  if (chart.restricted.includes(dataKey)) return 'restricted';
  if (chart.unavailable.includes(dataKey)) return 'unavailable';

  return null;
}

function RecordSection({
  id,
  patient,
  chart,
  activeFacilityId,
  canBook,
  canManage,
  canCheckIn,
  onBook,
  onViewAppointment,
  onRescheduleAppointment,
  onCancelAppointment,
  onCheckInAppointment,
  onEditPatient,
  vitalsEntryHref,
}: {
  id: SectionId;
  patient: PatientChartProps['patient'];
  chart: PatientChartData;
  onEditPatient: (() => void) | undefined;
  vitalsEntryHref: string | undefined;
  activeFacilityId: string | null;
  canBook: boolean;
  canManage: boolean;
  canCheckIn: boolean;
  onBook: () => void;
  onViewAppointment: (item: PatientChartAppointment) => void;
  onRescheduleAppointment: (item: PatientChartAppointment) => void;
  onCancelAppointment: (item: PatientChartAppointment) => void;
  onCheckInAppointment: (item: PatientChartAppointment) => void;
}): ReactNode {
  const section = sections.find((item) => item.id === id);
  if (!section) return null;
  const state = sectionAccess(chart, section.dataKey);

  switch (id) {
    case 'overview':
      return (
        <PatientSummary
          patient={patient}
          chart={chart}
          onEditPatient={onEditPatient}
          vitalsEntryHref={vitalsEntryHref}
        />
      );
    case 'timeline':
      return <PatientTimeline patient={patient} chart={chart} />;
    case 'conditions':
      return <PatientConditionsSection conditions={chart.conditions} state={state} />;
    case 'allergies':
      return <PatientAllergiesSection allergies={chart.allergies} state={state} />;
    case 'medications':
      return <PatientMedicationsSection medications={chart.medications} activeFacilityId={activeFacilityId} state={state} />;
    case 'vitals':
      return <PatientVitalsSection vitals={chart.vitals} state={state} />;
    case 'plans':
      return <PatientPlansSection plans={chart.plans} state={state} />;
    case 'procedures':
      return <PatientProceduresSection procedures={chart.procedures} state={state} />;
    case 'lab-results':
      return <PatientLabResultsSection chart={chart} state={state} />;
    case 'imaging':
      return <PatientImagingSection orders={chart.imagingOrders} state={state} />;
    case 'appointments':
      return (
        <PatientAppointmentsSection
          appointments={chart.appointments}
          canBook={canBook}
          canManage={canManage}
          canCheckIn={canCheckIn}
          onBook={onBook}
          onView={onViewAppointment}
          onReschedule={onRescheduleAppointment}
          onCancel={onCancelAppointment}
          onCheckIn={onCheckInAppointment}
          state={state}
        />
      );
    case 'visits':
      return <PatientVisitsSection visits={chart.visits} state={state} />;
    case 'programs':
      return <PatientProgramsSection programs={chart.programs} state={state} />;
    case 'referrals':
      return <PatientReferralsSection referrals={chart.referrals} state={state} />;
    case 'billing-history':
      return <PatientBillingSection billing={chart.billing} state={state} />;
  }
}

export function PatientChart({
  patient,
  activeEncounter,
  availableQueues,
  queueEntry,
  photoUrl,
  chart,
}: PatientChartProps): ReactNode {
  const { url, props: pageProps } = usePage();
  const can = useCan();
  const canBook = can(permissions.scheduling.book);
  const canManage = can(permissions.scheduling.manage);
  const canEditPatient = can(permissions.patient.update);
  const canMarkPatientDeceased = can(permissions.patient.markDeceased);
  const canCheckIn = can(permissions.encounter.create) && activeEncounter === null;
  const canOpenQueueWorkflow = can(permissions.queue.ticketView)
    && can(permissions.clinical.access)
    && can(permissions.patient.read)
    && can(permissions.encounter.read)
    && can(permissions.clinical.vitalsRead)
    && can(permissions.clinical.triageRead)
    && activeEncounter !== null
    && queueEntry !== null
    && ['WAITING', 'CALLED', 'IN_SERVICE'].includes(queueEntry.status)
    && patient.status !== 'DECEASED'
    && patient.status !== 'MERGED';
  const workflowAction = canOpenQueueWorkflow && queueEntry
    ? queueEntry.serviceCapability === 'TRIAGE'
      ? {
          label: queueEntry.status === 'IN_SERVICE'
            ? 'Continue nursing assessment'
            : 'Open nursing assessment',
          href: routes.workspaces.nurseStationEntry(queueEntry.entryId),
        }
      : queueEntry.serviceCapability === 'CONSULTATION'
        && can(permissions.clinical.consultationsRead)
        ? {
            label: queueEntry.status === 'IN_SERVICE'
              ? 'Continue consultation'
              : 'Open consultation',
            href: routes.workspaces.clinicianEntry(queueEntry.entryId),
          }
        : undefined
    : undefined;
  const canOpenVitalsWorkflow = can(permissions.clinical.vitalsRecord)
    && can(permissions.queue.ticketView)
    && can(permissions.clinical.access)
    && can(permissions.clinical.vitalsRead)
    && can(permissions.clinical.triageRead)
    && can(permissions.encounter.read)
    && can(permissions.patient.read)
    && activeEncounter !== null
    && queueEntry?.serviceCapability === 'TRIAGE'
    && queueEntry.status === 'IN_SERVICE';
  /**
   * Single active workspace — one union instead of one boolean per panel.
   * Opening an unrelated workspace replaces the current one, so Book never
   * stacks under Check-in or Details. `key` on each panel below remounts the
   * form on switch, discarding the previous draft via React itself.
   * Reschedule/Cancel stay as true modals outside this group.
   */
  type ChartWorkspace =
    | { kind: 'book' }
    | { kind: 'checkin'; target: CheckInTarget }
    | { kind: 'details'; item: PatientChartAppointment };
  const [workspace, setWorkspace] = useState<ChartWorkspace | null>(null);
  const [patientAction, setPatientAction] = useState<'edit' | 'deceased' | null>(null);
  const [dialogItem, setDialogItem] = useState<{ item: PatientChartAppointment; kind: 'reschedule' | 'cancel' } | null>(null);
  const {
    setDirty: setWorkspaceDirty,
    pendingSwitch,
    requestSwitch,
    confirmPendingSwitch,
    cancelPendingSwitch,
  } = useWorkspaceGuard();
  const selected = activeSection(url, patient.patientId);
  const now = new Date();
  const currentMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;

  const openWorkspace = useCallback((next: ChartWorkspace | null) => {
    setWorkspaceDirty(false);
    setWorkspace(next);
  }, [setWorkspaceDirty]);

  const closeWorkspace = useCallback(() => {
    requestSwitch(() => openWorkspace(null));
  }, [requestSwitch, openWorkspace]);

  const openBook = useCallback(() => {
    requestSwitch(() => openWorkspace({ kind: 'book' }));
  }, [requestSwitch, openWorkspace]);

  const openCheckIn = useCallback(() => {
    requestSwitch(() => openWorkspace({
      kind: 'checkin',
      target: {
        patientId: patient.patientId,
        displayName: patient.displayName,
        patientNumber: patient.patientNumber,
      },
    }));
  }, [patient.patientId, patient.displayName, patient.patientNumber, requestSwitch, openWorkspace]);

  function appointmentTarget(item: PatientChartAppointment): CheckInTarget {
    return {
      patientId: patient.patientId,
      displayName: patient.displayName,
      patientNumber: patient.patientNumber,
      appointmentId: item.id,
      appointmentNumber: item.number,
      appointmentLabel: [
        item.appointmentTypeName,
        item.resourceName,
        formatClinicalDateTime(item.start) ?? item.start,
      ].filter((part) => part !== null && part !== '').join(' · ') || null,
    };
  }

  function toDetails(item: PatientChartAppointment): AppointmentDetails {
    return {
      id: item.id,
      appointmentNumber: item.number,
      scheduledStart: item.start,
      scheduledEnd: item.end,
      status: item.status,
      reason: item.reason,
      appointmentTypeName: item.appointmentTypeName,
      resourceName: item.resourceName,
      patient: {
        patientId: patient.patientId,
        patientNumber: patient.patientNumber,
        displayName: patient.displayName,
      },
    };
  }

  function toActionable(item: PatientChartAppointment): ActionableAppointment {
    return {
      id: item.id,
      appointmentNumber: item.number,
      scheduledStart: item.start,
      scheduledEnd: item.end,
      status: item.status,
      appointmentTypeId: item.appointmentTypeId,
      appointmentTypeName: item.appointmentTypeName,
    };
  }

  return (
    <div className="patient-chart patient-chart--patient">
      <main className="patient-chart__main">
        <PatientBanner
          patient={patient}
          photoUrl={photoUrl}
          activeEncounter={activeEncounter}
          queueEntry={queueEntry}
          chart={chart}
          stickyNote={<PatientStickyNote patientId={patient.patientId} />}
          actions={(
            <PatientActionsMenu
              patient={patient}
              canBook={canBook}
              canCheckIn={canCheckIn}
              workflowAction={workflowAction}
              onBook={openBook}
              onCheckIn={openCheckIn}
              onEditPatient={canEditPatient ? () => setPatientAction('edit') : undefined}
              onMarkDeceased={() => setPatientAction('deceased')}
            />
          )}
        />
        {/* Section navigation lives in the contextual app sidebar
            (layouts/AppLayout/navigation.ts) — never duplicated here. */}
        <section className="patient-chart__section" aria-label={sections.find((section) => section.id === selected)?.label ?? 'Patient Summary'}>
          <div className="patient-chart__section-content">
            <RecordSection
              id={selected}
              patient={patient}
              chart={chart}
              onEditPatient={canEditPatient ? () => setPatientAction('edit') : undefined}
              vitalsEntryHref={canOpenVitalsWorkflow && queueEntry
                ? routes.workspaces.nurseStationEntry(queueEntry.entryId)
                : undefined}
              activeFacilityId={pageProps.auth.activeFacility}
              canBook={canBook}
              canManage={canManage}
              canCheckIn={canCheckIn}
              onBook={openBook}
              onViewAppointment={(item) => requestSwitch(() => openWorkspace({ kind: 'details', item }))}
              onRescheduleAppointment={(item) => setDialogItem({ item, kind: 'reschedule' })}
              onCancelAppointment={(item) => setDialogItem({ item, kind: 'cancel' })}
              onCheckInAppointment={(item) => requestSwitch(() => openWorkspace({ kind: 'checkin', target: appointmentTarget(item) }))}
            />
          </div>
        </section>
      </main>
      {workspace?.kind === 'book' && canBook ? (
        <BookAppointmentPanel
          key={`book:${patient.patientId}`}
          month={currentMonth}
          fixedPatient={{
            patientId: patient.patientId,
            patientNumber: patient.patientNumber,
            displayName: patient.displayName,
            sex: patient.sex,
            photoUrl,
          }}
          returnTo={routes.patient.chart(patient.patientId, 'appointments')}
          offsetInlineEnd="0px"
          onClose={closeWorkspace}
          onDirtyChange={setWorkspaceDirty}
        />
      ) : null}
      {workspace?.kind === 'checkin' && canCheckIn ? (
        <CheckInAction
          key={`checkin:${workspace.target.patientId}:${workspace.target.appointmentId ?? 'walk-in'}`}
          target={workspace.target}
          availableQueues={availableQueues}
          offsetInlineEnd="0px"
          onClose={closeWorkspace}
          onDirtyChange={setWorkspaceDirty}
        />
      ) : null}
      {workspace?.kind === 'details' ? (
        <AppointmentDetailsPanel
          key={`details:${workspace.item.id}`}
          appointment={toDetails(workspace.item)}
          onClose={closeWorkspace}
          offsetInlineEnd="0px"
        />
      ) : null}
      {dialogItem && canManage ? (
        dialogItem.kind === 'reschedule' ? (
          <RescheduleAppointmentDialog
            appointment={toActionable(dialogItem.item)}
            open
            onClose={() => setDialogItem(null)}
          />
        ) : (
          <CancelAppointmentDialog
            appointment={toActionable(dialogItem.item)}
            open
            onClose={() => setDialogItem(null)}
          />
        )
      ) : null}
      {canEditPatient ? (
        <EditDemographicsDialog
          patient={patient}
          open={patientAction === 'edit'}
          onClose={() => setPatientAction(null)}
          offsetInlineEnd="0px"
          renderTrigger={() => null}
        />
      ) : null}
      {canMarkPatientDeceased ? (
        <MarkDeceasedDialog
          patient={patient}
          open={patientAction === 'deceased'}
          onClose={() => setPatientAction(null)}
          offsetInlineEnd="0px"
          renderTrigger={() => null}
        />
      ) : null}
      {/* Mounted only when open: Carbon Modal stays in the DOM hidden
          when closed, which would leak the heading into queries. */}
      <DiscardChangesModal
        pending={pendingSwitch !== null}
        onKeep={cancelPendingSwitch}
        onDiscard={confirmPendingSwitch}
      />
    </div>
  );
}
