import { Button, InlineNotification } from '@carbon/react';
import { Link, usePage } from '@inertiajs/react';
import { useCallback, useState, type ReactNode } from 'react';
import { EmptyState, ErrorState, Status } from '@/components/ui';
import { DiscardChangesModal, useWorkspaceGuard } from '@/components/workspace';
import {
  AppointmentDetailsPanel,
  type AppointmentDetails,
} from '@/features/appointments/components/AppointmentDetailsPanel';
import {
  CancelAppointmentDialog,
  RescheduleAppointmentDialog,
  appointmentStatusKind,
  appointmentStatusLabel,
  type ActionableAppointment,
} from '@/features/appointments/components/AppointmentLifecycleButtons';
import { BookAppointmentPanel } from '@/features/appointments/components/BookAppointmentPanel';
import {
  CheckInAction,
  type CheckInTarget,
} from '@/features/front-desk/components/CheckInAction';
import { PatientBanner } from '@/features/patients/components/PatientBanner';
import { QuickActions, type ChartRailName } from '@/features/patients/components/QuickActions';
import { EditDemographicsDialog } from '@/features/patients/forms/EditDemographicsDialog';
import { formatClinicalDateTime } from '@/lib/formatting';
import { permissions, useCan } from '@/lib/authorization';
import { routes } from '@/app/routes';
import type {
  PatientChartAllergy,
  PatientChartAppointment,
  PatientChartBilling,
  PatientChartCondition,
  PatientChartData,
  PatientChartImagingOrder,
  PatientChartLabOrder,
  PatientChartMedication,
  PatientChartPlan,
  PatientChartProcedure,
  PatientChartProgram,
  PatientChartProps,
  PatientChartReferral,
  PatientChartVisit,
  PatientChartVital,
} from '@/features/patients/types/chart';

const sections = [
  { id: 'overview', label: 'Patient Summary', dataKey: null },
  { id: 'allergies', label: 'Allergies', dataKey: 'allergies' },
  { id: 'conditions', label: 'Conditions', dataKey: 'conditions' },
  { id: 'procedures', label: 'Procedures', dataKey: 'procedures' },
  { id: 'plans', label: 'Plans', dataKey: 'plans' },
  { id: 'vitals', label: 'Vitals & Biometrics', dataKey: 'vitals' },
  { id: 'medications', label: 'Medications', dataKey: 'medications' },
  { id: 'lab-results', label: 'Results', dataKey: 'results' },
  { id: 'imaging', label: 'Orders', dataKey: 'orders' },
  { id: 'appointments', label: 'Appointments', dataKey: 'appointments' },
  { id: 'visits', label: 'Visits', dataKey: 'visits' },
  { id: 'programs', label: 'Programs', dataKey: 'programs' },
  { id: 'referrals', label: 'Referrals', dataKey: 'referrals' },
  { id: 'billing-history', label: 'Billing History', dataKey: 'billing-history' },
] as const;

type SectionId = (typeof sections)[number]['id'];
type FieldValue = string | number | boolean | null;
type RecordRow = {
  title: string;
  fields: Array<[string, FieldValue]>;
  action?: { href: string; label: string };
  note?: string;
};

function activeSection(url: string, patientId: string): SectionId {
  const path = new URL(url, 'http://afyanova.local').pathname;
  const match = path.match(new RegExp(`^/patient/${escapeRegExp(patientId)}/chart(?:/([^/]+))?/?$`));
  const requested = match?.[1];

  return sections.some((section) => section.id === requested) ? requested as SectionId : 'overview';
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function printable(value: FieldValue): string {
  if (value === null || value === '') return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';

  return String(value);
}

function RecordList({ rows }: { rows: RecordRow[] }): ReactNode {
  return (
    <div className="patient-chart__record-list">
      {rows.map((row, index) => (
        <article className="patient-chart__record" key={`${row.title}-${index}`}>
          <h3 className="patient-chart__record-title">{row.title}</h3>
          <dl className="patient-chart__record-fields">
            {row.fields.map(([label, value]) => (
              <div className="patient-chart__field" key={label}>
                <dt>{label}</dt>
                <dd>{printable(value)}</dd>
              </div>
            ))}
          </dl>
          {row.action ? (
            <Link className="patient-chart__record-action" href={row.action.href}>
              {row.action.label}
            </Link>
          ) : null}
          {row.note ? <p className="patient-chart__record-note">{row.note}</p> : null}
        </article>
      ))}
    </div>
  );
}

function allergiesRows(data: PatientChartAllergy[]): RecordRow[] {
  return data.map((item) => ({
    title: item.substance,
    fields: [
      ['Severity', item.severity],
      ['Status', item.status],
      ['Verification', item.verification],
      ['Reaction', item.reaction],
      ['Onset', formatClinicalDateTime(item.onset)],
    ],
  }));
}

function conditionsRows(data: PatientChartCondition[]): RecordRow[] {
  return data.map((item) => ({
    title: item.description,
    fields: [['Code', item.code], ['Status', item.status], ['Onset', formatClinicalDateTime(item.onset)]],
  }));
}

function proceduresRows(data: PatientChartProcedure[]): RecordRow[] {
  return data.map((item) => ({
    title: item.number,
    fields: [
      ['Category', item.category],
      ['Status', item.status],
      ['Outcome', item.outcome],
      ['Completed at', item.completedAt],
    ],
  }));
}

function plansRows(data: PatientChartPlan[]): RecordRow[] {
  return data.map((item) => ({
    title: item.type,
    fields: [['Intent', item.intent], ['Status', item.status], ['Follow-up', formatClinicalDateTime(item.followUp)]],
  }));
}

function vitalsRows(data: PatientChartVital[]): RecordRow[] {
  return data.map((item) => ({
    title: formatClinicalDateTime(item.occurredAt) ?? item.occurredAt,
    fields: [
      ['Temperature', item.temperature],
      ['Heart rate', item.heartRate],
      ['Respiratory rate', item.respiratoryRate],
      ['Systolic blood pressure', item.systolicBp],
      ['Diastolic blood pressure', item.diastolicBp],
      ['Oxygen saturation', item.oxygenSaturation],
      ['Weight', item.weight],
      ['Height', item.height],
    ],
  }));
}

function medicationsRows(data: PatientChartMedication[], activeFacilityId: string | null): RecordRow[] {
  return data.map((item) => {
    const belongsToActiveFacility = activeFacilityId !== null && item.facilityId === activeFacilityId;

    return {
      title: item.medication ?? 'Medication',
      fields: [
        ['Prescription number', item.number],
        ['Dose', item.dose],
        ['Frequency', item.frequency],
        ['Status', item.status],
        ['Recorded at', formatClinicalDateTime(item.recordedAt)],
      ],
      ...(belongsToActiveFacility
        ? {
            action: {
              href: `${routes.workspaces.pharmacy}?view=prescriptions&rx_id=${encodeURIComponent(item.id)}`,
              label: 'Open prescription in Pharmacy',
            },
          }
        : { note: 'This prescription belongs to another facility.' }),
    };
  });
}

function labRows(data: PatientChartData): RecordRow[] {
  return data.labOrders.map((order: PatientChartLabOrder) => {
    const results = data.labResults[order.orderId] ?? [];
    const itemSummary = order.items.map((item) => `${item.testName} (${item.status})`).join(', ');

    return {
      title: order.orderNumber,
      fields: [
        ['Priority', order.priority],
        ['Status', order.status],
        ['Created at', formatClinicalDateTime(order.createdAt)],
        ['Tests', itemSummary || null],
        ...results.flatMap((result) => [
          [`${result.parameter} result`, [result.value, result.unit].filter((part) => part !== null && part !== '').join(' ') || null] as [string, FieldValue],
          [`${result.parameter} flag`, result.flag] as [string, FieldValue],
        ]),
      ],
    };
  });
}

function imagingRows(data: PatientChartImagingOrder[]): RecordRow[] {
  return data.map((order): RecordRow => ({
    title: order.orderNumber,
    fields: [
      ['Priority', order.priority],
      ['Status', order.status],
      ['Created at', formatClinicalDateTime(order.createdAt)],
      ['Services', order.items.map((item) => `${item.serviceName ?? item.serviceCode ?? 'Imaging'} (${item.status})`).join(', ') || null],
    ],
  }));
}

function appointmentsRows(data: PatientChartAppointment[]): RecordRow[] {
  return data.map((item) => ({
    title: item.number,
    fields: [['Start', item.start], ['End', item.end], ['Status', item.status], ['Reason', item.reason]],
  }));
}

function visitsRows(data: PatientChartVisit[]): RecordRow[] {
  return data.map((item) => ({
    title: item.number,
    fields: [
      ['Type', item.encounterType],
      ['Status', item.status],
      ['Disposition', item.disposition],
      ['Department', item.departmentName ?? item.departmentId],
      ['Unit', item.unitName ?? item.unitId],
      ['Arrived at', formatClinicalDateTime(item.arrivedAt)],
      ['Started at', formatClinicalDateTime(item.startedAt)],
      ['Admitted at', formatClinicalDateTime(item.admittedAt)],
      ['Completed at', formatClinicalDateTime(item.completedAt)],
    ],
  }));
}

function toActionableAppointment(item: PatientChartAppointment): ActionableAppointment {
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

/**
 * Chart appointment rows with contextual lifecycle entries (foundation §15).
 * Same canonical View/Reschedule/Cancel workflows as the calendar — the
 * chart never implements appointment logic itself.
 */
function ChartAppointmentRows({
  appointments,
  canManage,
  canCheckIn,
  onView,
  onReschedule,
  onCancel,
  onCheckIn,
}: {
  appointments: PatientChartAppointment[];
  canManage: boolean;
  canCheckIn: boolean;
  onView: (item: PatientChartAppointment) => void;
  onReschedule: (item: PatientChartAppointment) => void;
  onCancel: (item: PatientChartAppointment) => void;
  onCheckIn: (item: PatientChartAppointment) => void;
}): ReactNode {
  return (
    <ol style={{ margin: 0, padding: 0, listStyle: 'none' }}>
      {appointments.map((item) => {
        const canReschedule = canManage && (item.status === 'BOOKED' || item.status === 'CONFIRMED');
        const canCancel = canManage && item.status !== 'CANCELLED';
        // The backend links arrival server-side for these statuses (and
        // skips it when already ARRIVED) — other statuses cannot check in.
        const canCheckInRow = canCheckIn
          && (item.status === 'BOOKED' || item.status === 'CONFIRMED' || item.status === 'ARRIVED');
        return (
          <li
            key={item.id}
            style={{ padding: '0.75rem 0' }}
          >
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '0.5rem' }}>
              <strong>{item.number}</strong>
              <Status kind={appointmentStatusKind(item.status)}>{appointmentStatusLabel(item.status)}</Status>
            </div>
            <p style={{ margin: '0.25rem 0 0' }}>
              {formatClinicalDateTime(item.start) ?? item.start}
              {' – '}
              {formatClinicalDateTime(item.end) ?? item.end}
              {item.appointmentTypeName ? ` · ${item.appointmentTypeName}` : ''}
              {item.resourceName ? ` · ${item.resourceName}` : ''}
            </p>
            {item.reason ? <p style={{ margin: '0.25rem 0 0' }}>“{item.reason}”</p> : null}
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.125rem', marginInlineStart: '-0.5rem' }}>
              <Button kind="ghost" size="sm" type="button" onClick={() => onView(item)}>
                View
              </Button>
              {canReschedule ? (
                <Button kind="ghost" size="sm" type="button" onClick={() => onReschedule(item)}>
                  Reschedule
                </Button>
              ) : null}
              {canCancel ? (
                <Button kind="ghost" size="sm" type="button" onClick={() => onCancel(item)}>
                  Cancel
                </Button>
              ) : null}
              {canCheckInRow ? (
                <Button kind="ghost" size="sm" type="button" onClick={() => onCheckIn(item)}>
                  {`Check in appointment ${item.number}`}
                </Button>
              ) : null}
            </div>
          </li>
        );
      })}
    </ol>
  );
}

function programsRows(data: PatientChartProgram[]): RecordRow[] {
  return data.map((item) => ({
    title: item.type,
    fields: [
      ['Subtype', item.subtype],
      ['Status', item.status],
      ['Opened at', formatClinicalDateTime(item.openedAt)],
      ['Closed at', formatClinicalDateTime(item.closedAt)],
    ],
  }));
}

function referralsRows(data: PatientChartReferral[]): RecordRow[] {
  return data.map((item) => ({
    title: item.specialty ?? item.direction,
    fields: [['Direction', item.direction], ['Priority', item.priority], ['Status', item.status]],
  }));
}

function billingRows(billing: PatientChartBilling): RecordRow[] {
  const rows: RecordRow[] = [];
  if (billing.account) {
    rows.push({
      title: billing.account.accountNumber,
      fields: [['Status', billing.account.status], ['Currency', billing.account.currency]],
    });
  }
  if (billing.outstanding) {
    rows.push({
      title: 'Outstanding balance',
      fields: [
        ['Outstanding', billing.outstanding.outstanding],
        ['Charged total', billing.outstanding.chargedTotal],
        ['Paid total', billing.outstanding.paidTotal],
        ['Settled total', billing.outstanding.settledTotal],
        ['Currency', billing.outstanding.currency],
      ],
    });
  }
  rows.push(...billing.ledger.map((entry): RecordRow => ({
    title: `${entry.entryType} · ${formatClinicalDateTime(entry.postedAt) ?? entry.postedAt}`,
    fields: [
      ['Direction', entry.direction],
      ['Amount', entry.amount],
      ['Currency', entry.currency],
    ],
  })));

  return rows;
}

function sectionRows(
  id: SectionId,
  chart: PatientChartData,
  activeFacilityId: string | null,
): RecordRow[] {
  switch (id) {
    case 'overview': return [];
    case 'allergies': return allergiesRows(chart.allergies);
    case 'conditions': return conditionsRows(chart.conditions);
    case 'procedures': return proceduresRows(chart.procedures);
    case 'plans': return plansRows(chart.plans);
    case 'vitals': return vitalsRows(chart.vitals);
    case 'medications': return medicationsRows(chart.medications, activeFacilityId);
    case 'lab-results': return labRows(chart);
    case 'imaging': return imagingRows(chart.imagingOrders);
    case 'appointments': return appointmentsRows(chart.appointments);
    case 'visits': return visitsRows(chart.visits);
    case 'programs': return programsRows(chart.programs);
    case 'referrals': return referralsRows(chart.referrals);
    case 'billing-history': return billingRows(chart.billing);
  }
}

function sectionIsEmpty(id: SectionId, chart: PatientChartData): boolean {
  switch (id) {
    case 'overview': return false;
    case 'allergies': return chart.allergies.length === 0;
    case 'conditions': return chart.conditions.length === 0;
    case 'procedures': return chart.procedures.length === 0;
    case 'plans': return chart.plans.length === 0;
    case 'vitals': return chart.vitals.length === 0;
    case 'medications': return chart.medications.length === 0;
    case 'lab-results': return chart.labOrders.length === 0;
    case 'imaging': return chart.imagingOrders.length === 0;
    case 'appointments': return chart.appointments.length === 0;
    case 'visits': return chart.visits.length === 0;
    case 'programs': return chart.programs.length === 0;
    case 'referrals': return chart.referrals.length === 0;
    case 'billing-history':
      return chart.billing.account === null && chart.billing.outstanding === null && chart.billing.ledger.length === 0;
  }
}

function ChartSection({
  id,
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
}: {
  id: SectionId;
  chart: PatientChartData;
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

  if (section.dataKey && chart.restricted.includes(section.dataKey)) {
    return (
      <InlineNotification
        kind="warning"
        title="Restricted section"
        subtitle="You do not have permission to view this section."
        lowContrast
      />
    );
  }
  if (section.dataKey && chart.unavailable.includes(section.dataKey)) {
    return <ErrorState title={`${section.label} unavailable`} message="This section could not be loaded." />;
  }
  if (id === 'appointments') {
    // Contextual entry point into the canonical Appointments workflows
    // (foundation §15) — same booking panel, details, and dialogs as the
    // calendar; the chart never implements appointment logic itself.
    return (
      <>
        {canBook ? (
          <div style={{ marginBlockEnd: '1rem' }}>
            <Button size="sm" onClick={onBook}>
              Book appointment
            </Button>
          </div>
        ) : null}
        {sectionIsEmpty(id, chart)
          ? <EmptyState title={`No ${section.label.toLowerCase()} to display`} />
          : (
            <ChartAppointmentRows
              appointments={chart.appointments}
              canManage={canManage}
              canCheckIn={canCheckIn}
              onView={onViewAppointment}
              onReschedule={onRescheduleAppointment}
              onCancel={onCancelAppointment}
              onCheckIn={onCheckInAppointment}
            />
          )}
      </>
    );
  }
  if (sectionIsEmpty(id, chart)) {
    return <EmptyState title={`No ${section.label.toLowerCase()} to display`} />;
  }

  return <RecordList rows={sectionRows(id, chart, activeFacilityId)} />;
}

export function PatientChart({
  patient,
  activeEncounter,
  availableQueues,
  photoUrl,
  chart,
}: PatientChartProps): ReactNode {
  const { url, props: pageProps } = usePage();
  const can = useCan();
  const canBook = can(permissions.scheduling.book);
  const canManage = can(permissions.scheduling.manage);
  const canCheckIn = can(permissions.encounter.create) && activeEncounter === null;
  /**
   * Single active workspace — one union instead of one boolean per panel.
   * Opening an unrelated workspace replaces the current one, so Edit never
   * stacks under Book/Check-in/Details. `key` on each panel below remounts
   * the form on switch, discarding the previous draft via React itself.
   * Reschedule/Cancel stay as true modals outside this group.
   */
  type ChartWorkspace =
    | { kind: 'edit' }
    | { kind: 'book' }
    | { kind: 'checkin'; target: CheckInTarget }
    | { kind: 'details'; item: PatientChartAppointment };
  const [workspace, setWorkspace] = useState<ChartWorkspace | null>(null);
  const [dialogItem, setDialogItem] = useState<{ item: PatientChartAppointment; kind: 'reschedule' | 'cancel' } | null>(null);
  const {
    setDirty: setWorkspaceDirty,
    pendingSwitch,
    requestSwitch,
    confirmPendingSwitch,
    cancelPendingSwitch,
  } = useWorkspaceGuard();
  const selected = activeSection(url, patient.patientId);
  const selectedLabel = sections.find((section) => section.id === selected)?.label ?? 'Patient Summary';
  const now = new Date();
  const currentMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;

  const openWorkspace = useCallback((next: ChartWorkspace | null) => {
    setWorkspaceDirty(false);
    setWorkspace(next);
  }, [setWorkspaceDirty]);

  const closeWorkspace = useCallback(() => {
    requestSwitch(() => openWorkspace(null));
  }, [requestSwitch, openWorkspace]);

  const selectRail = useCallback((name: ChartRailName) => {
    requestSwitch(() => {
      setWorkspaceDirty(false);
      setWorkspace((current) => {
        if (current?.kind === name) return null;
        if (name === 'edit') return { kind: 'edit' };
        if (name === 'book') return { kind: 'book' };
        return {
          kind: 'checkin',
          target: {
            patientId: patient.patientId,
            displayName: patient.displayName,
            patientNumber: patient.patientNumber,
          },
        };
      });
    });
  }, [patient.patientId, patient.displayName, patient.patientNumber, requestSwitch, setWorkspaceDirty]);

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

  return (
    <div className="patient-chart">
      <main className="patient-chart__main">
        <PatientBanner
          patient={patient}
          photoUrl={photoUrl}
          activeEncounter={activeEncounter}
        />
        {/* Section navigation lives in the contextual app sidebar
            (layouts/AppLayout/navigation.ts) — never duplicated here. */}
        {selected !== 'overview' ? (
          <section className="patient-chart__section" aria-label={selectedLabel}>
            <div className="patient-chart__section-content">
              <ChartSection
                id={selected}
                chart={chart}
                activeFacilityId={pageProps.auth.activeFacility}
                canBook={canBook}
                canManage={canManage}
                canCheckIn={canCheckIn}
                onBook={() => requestSwitch(() => openWorkspace({ kind: 'book' }))}
                onViewAppointment={(item) => requestSwitch(() => openWorkspace({ kind: 'details', item }))}
                onRescheduleAppointment={(item) => setDialogItem({ item, kind: 'reschedule' })}
                onCancelAppointment={(item) => setDialogItem({ item, kind: 'cancel' })}
                onCheckInAppointment={(item) => requestSwitch(() => openWorkspace({ kind: 'checkin', target: appointmentTarget(item) }))}
              />
            </div>
          </section>
        ) : null}
      </main>
      <QuickActions
        active={workspace?.kind === 'details' ? null : (workspace?.kind ?? null)}
        onSelect={selectRail}
        canCheckIn={canCheckIn}
        patientId={patient.patientId}
      />
      {workspace?.kind === 'edit' ? (
        <EditDemographicsDialog
          key={`edit:${patient.patientId}`}
          patient={patient}
          open
          onClose={closeWorkspace}
          offsetInlineEnd="3rem"
          onDirtyChange={setWorkspaceDirty}
        />
      ) : null}
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
          offsetInlineEnd="3rem"
          onClose={closeWorkspace}
          onDirtyChange={setWorkspaceDirty}
        />
      ) : null}
      {workspace?.kind === 'checkin' && canCheckIn ? (
        <CheckInAction
          key={`checkin:${workspace.target.patientId}:${workspace.target.appointmentId ?? 'walk-in'}`}
          target={workspace.target}
          availableQueues={availableQueues}
          offsetInlineEnd="3rem"
          onClose={closeWorkspace}
          onDirtyChange={setWorkspaceDirty}
        />
      ) : null}
      {workspace?.kind === 'details' ? (
        <AppointmentDetailsPanel
          key={`details:${workspace.item.id}`}
          appointment={toDetails(workspace.item)}
          onClose={closeWorkspace}
          offsetInlineEnd="3rem"
        />
      ) : null}
      {dialogItem && canManage ? (
        dialogItem.kind === 'reschedule' ? (
          <RescheduleAppointmentDialog
            appointment={toActionableAppointment(dialogItem.item)}
            open
            onClose={() => setDialogItem(null)}
          />
        ) : (
          <CancelAppointmentDialog
            appointment={toActionableAppointment(dialogItem.item)}
            open
            onClose={() => setDialogItem(null)}
          />
        )
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
