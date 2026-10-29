import type { ReactNode } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Button, InlineNotification, Tag } from '@carbon/react';
import { PageHeader, StatisticsCards } from '@/components/ui';
import { DiscardChangesModal, useWorkspaceGuard } from '@/components/workspace';
import { routes } from '@/app/routes';
import { Status } from '@/components/ui/Status';
import { formatDobDate, formatTimeInZone, dayInZone } from '@/lib/formatting';
import { appointmentStatusKind, appointmentStatusLabel } from '@/features/appointments/components/AppointmentLifecycleButtons';
import {
  CheckInAction,
  type CheckInTarget,
} from './CheckInAction';
import { FrontDeskQuickActions } from './FrontDeskQuickActions';
import { FrontDeskRosterTable, type FrontDeskRosterRow } from './FrontDeskRosterTable';
import { FrontDeskVisitSummary } from './FrontDeskVisitSummary';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import type {
  ReceptionAppointmentItem,
  ReceptionEncounterItem,
  ReceptionMetrics,
  ReceptionPreselectedPatient,
  ReceptionPageProps,
  ReceptionQueueOption,
  ReceptionWeekDay,
} from '../types/reception';
import { permissions, useCan } from '@/lib/authorization';

type EncounterStatusKind = 'active' | 'inactive' | 'warning' | 'error' | 'info';

function encounterStatusKind(status: string): EncounterStatusKind {
  switch (status) {
    case 'ARRIVED':
    case 'ACTIVE':
      return 'active';
    case 'COMPLETED':
      return 'inactive';
    case 'CANCELLED':
    case 'ABANDONED':
    case 'LEFT_WITHOUT_BEING_SEEN':
      return 'error';
    default:
      return 'info';
  }
}

function queueStatusLabel(status: string): string {
  switch (status) {
    case 'WAITING': return 'Waiting';
    case 'CALLED': return 'Called';
    case 'IN_SERVICE': return 'In service';
    case 'ON_HOLD': return 'On hold';
    default: return status.replaceAll('_', ' ');
  }
}

function boardHref(date: string, rosterStatus: string, rosterFilter: string): string {
  const params = new URLSearchParams({ date });
  if (rosterStatus !== 'ALL') params.set('status', rosterStatus);
  if (rosterFilter !== '') params.set('filter', rosterFilter);
  return `${routes.workspaces.reception}?${params.toString()}`;
}

function MetricsStrip({
  metrics,
  isToday,
}: {
  metrics: ReceptionMetrics;
  isToday: boolean;
}): ReactNode {
  const cards = [
    {
      key: 'ALL',
      label: isToday ? 'All today' : 'All',
      value: metrics.totalEncounters + metrics.totalAppointments,
    },
    { key: 'ARRIVED', label: 'Arrived encounters', value: metrics.waitingEncounters },
    { key: 'IN_SERVICE', label: 'Patients in service', value: metrics.inServicePatients ?? '—' },
    { key: 'COMPLETED', label: 'Completed encounters', value: metrics.completedEncounters },
  ];
  return (
    <StatisticsCards
      label="Front Desk summary"
      className="front-desk-metrics"
      cards={cards.map((card) => ({
        id: card.key,
        label: card.label,
        value: card.value,
        detail: card.key === 'ALL'
          ? `roster entries · ${metrics.expectedAppointments} expected ${metrics.expectedAppointments === 1 ? 'appointment' : 'appointments'}`
          : undefined,
      }))}
    />
  );
}

function WeekStrip({
  days,
  weekLabel,
  rosterStatus,
  rosterFilter,
}: {
  days: ReceptionWeekDay[];
  weekLabel: string;
  rosterStatus: string;
  rosterFilter: string;
}): ReactNode {
  const today = days.find((day) => day.isToday);
  const selectedDay = days.find((day) => day.isSelected);

  return (
    <nav className="front-desk-week" aria-label="Front Desk week">
      <span className="front-desk-week__label">{weekLabel}</span>
      <ol>
        {days.map((day) => (
          <li key={day.date}>
            <Link
              className={[
                'front-desk-week__day',
                day.isToday ? 'front-desk-week__day--today' : '',
                day.isSelected ? 'front-desk-week__day--selected' : '',
              ].filter(Boolean).join(' ')}
              href={boardHref(day.date, rosterStatus, rosterFilter)}
              preserveScroll
              preserveState
              aria-current={day.isSelected ? 'date' : undefined}
              aria-label={`${day.label}${day.isToday ? ', today' : ''}`}
            >
              {day.label}
            </Link>
          </li>
        ))}
      </ol>
      {today && !selectedDay?.isToday ? (
        <Link
          className="front-desk-week__today"
          href={boardHref(today.date, rosterStatus, rosterFilter)}
          preserveScroll
          preserveState
          aria-label="Show today's roster"
        >
          Today
        </Link>
      ) : null}
    </nav>
  );
}

type RosterRow =
  | { kind: 'encounter'; sortKey: string; item: ReceptionEncounterItem }
  | { kind: 'appointment'; sortKey: string; item: ReceptionAppointmentItem };

function toRows(
  encounters: ReceptionEncounterItem[],
  appointments: ReceptionAppointmentItem[],
): RosterRow[] {
  const rows: RosterRow[] = [
    ...encounters.map((item): RosterRow => ({
      kind: 'encounter',
      sortKey: item.arrivedAt ?? item.startedAt ?? '\uffff',
      item,
    })),
    ...appointments.map((item): RosterRow => ({
      kind: 'appointment',
      sortKey: item.scheduledStart,
      item,
    })),
  ];
  return rows.sort((a, b) => a.sortKey.localeCompare(b.sortKey));
}

function PatientPhoto({ patientId, displayName }: { patientId: string; displayName: string }): ReactNode {
  const [failed, setFailed] = useState(false);
  if (failed) {
    return (
      <span className="appointment-agenda__photo-fallback" aria-hidden="true">
        {displayName.charAt(0).toUpperCase()}
      </span>
    );
  }
  return (
    <img
      className="appointment-agenda__photo"
      src={routes.patient.photo(patientId)}
      alt=""
      width={32}
      height={32}
      loading="lazy"
      onError={() => setFailed(true)}
    />
  );
}

function PatientIdentity({ patientId, patientNumber, displayName }: {
  patientId: string;
  patientNumber: string;
  displayName: string;
}): ReactNode {
  return (
    <span className="front-desk-row__patient">
      <PatientPhoto patientId={patientId} displayName={displayName} />
      <span className="front-desk-row__patient-text">
        <Link href={routes.patient.chart(patientId)}>{displayName}</Link>
        <span className="front-desk-row__patient-number">{patientNumber}</span>
      </span>
    </span>
  );
}

function EncounterTableRow({ item, timezone }: {
  item: ReceptionEncounterItem;
  timezone: string;
}): FrontDeskRosterRow {
  return {
    id: `encounter:${item.encounterId}`,
    time: (
      <time dateTime={item.arrivedAt ?? undefined}>
        {formatTimeInZone(item.arrivedAt, timezone) ?? '—'}
      </time>
    ),
    patient: item.patient ? (
      <PatientIdentity
        patientId={item.patient.patientId}
        patientNumber={item.patient.patientNumber}
        displayName={item.patient.displayName}
      />
    ) : (
      <span>Patient details restricted</span>
    ),
    details: (
      <span style={{ display: 'inline-flex', gap: '0.25rem', alignItems: 'center', flexWrap: 'wrap' }}>
        <Tag>
          <FrontDeskVisitSummary
            label={`Visit summary for ${item.encounterNumber}`}
            timezone={timezone}
            summary={{
              number: item.encounterNumber,
              type: item.encounterType,
              status: item.status,
              arrivedAt: item.arrivedAt,
              startedAt: item.startedAt,
            }}
          >
            {item.encounterNumber}
          </FrontDeskVisitSummary>
          {' · '}
          {item.encounterType.replaceAll('_', ' ')}
        </Tag>
        {item.queueStatus ? (
          <Tag type={item.queueStatus === 'IN_SERVICE' ? 'green' : item.queueStatus === 'ON_HOLD' ? 'red' : 'blue'}>
            {item.queueName ? `${item.queueName} · ` : ''}{queueStatusLabel(item.queueStatus)}
            {item.ticketNumber ? ` · #${item.ticketNumber}` : ''}
          </Tag>
        ) : null}
        {item.vitalsRecorded !== null ? (
          <Tag type={item.vitalsRecorded ? 'green' : 'gray'}>
            Vitals {item.vitalsRecorded ? 'recorded' : 'not recorded'}
          </Tag>
        ) : null}
        {item.triageRecorded !== null ? (
          <Tag type={item.triageRecorded ? 'green' : 'gray'}>
            Triage {item.triageRecorded ? 'recorded' : 'not recorded'}
          </Tag>
        ) : null}
      </span>
    ),
    status: <Status kind={encounterStatusKind(item.status)}>{item.status.replaceAll('_', ' ')}</Status>,
    actions: null,
  };
}

function AppointmentTableRow({
  item,
  timezone,
  canCheckIn,
  onCheckIn,
}: {
  item: ReceptionAppointmentItem;
  timezone: string;
  canCheckIn: boolean;
  onCheckIn: (target: CheckInTarget) => void;
}): FrontDeskRosterRow {
  const checkable = canCheckIn
    && (item.status === 'BOOKED' || item.status === 'CONFIRMED' || item.status === 'ARRIVED');
  return {
    id: `appointment:${item.id}`,
    time: <time dateTime={item.scheduledStart}>{formatTimeInZone(item.scheduledStart, timezone) ?? '—'}</time>,
    patient: item.patient ? (
      <PatientIdentity
        patientId={item.patient.patientId}
        patientNumber={item.patient.patientNumber}
        displayName={item.patient.displayName}
      />
    ) : (
      <span>Patient details restricted</span>
    ),
    details: (
      <span className="front-desk-row__meta">
        {item.appointmentNumber}
        {item.appointmentTypeName ? ` · ${item.appointmentTypeName}` : ''}
        {item.resourceName ? ` · ${item.resourceName}` : ''}
      </span>
    ),
    status: <Status kind={appointmentStatusKind(item.status)}>{appointmentStatusLabel(item.status)}</Status>,
    actions: checkable && item.patient ? (
      <Button
        kind="primary"
        size="sm"
        type="button"
        onClick={() => onCheckIn({
          patientId: item.patientId,
          displayName: item.patient?.displayName ?? item.patientId,
          patientNumber: item.patient?.patientNumber ?? '',
          appointmentId: item.id,
          appointmentNumber: item.appointmentNumber,
          appointmentLabel: [
            item.appointmentTypeName,
            item.resourceName,
            formatTimeInZone(item.scheduledStart, timezone),
          ].filter((part) => part !== null && part !== '').join(' · ') || null,
        })}
      >
        Check in
      </Button>
    ) : null,
  };
}

/**
 * Today board (slice 3): the merged arrival roster for one date — encounters
 * plus expected appointments in time order, with metrics, week navigation,
 * and roster search. Check-in uses the canonical `CheckInAction`; arrivals
 * vs check-in stay distinct per the foundation docs.
 */
export function FrontDeskBoard({
  date,
  timezone,
  encounters,
  appointments,
  metrics,
  availableQueues,
  protocolProcedures = [],
  preselectedPatient = null,
  activeEncounter = null,
  rosterStatus,
  rosterFilter,
  weekLabel,
  days,
  restricted,
}: {
  date: string;
  timezone: string;
  encounters: ReceptionEncounterItem[];
  appointments: ReceptionAppointmentItem[];
  metrics: ReceptionMetrics;
  availableQueues: ReceptionQueueOption[];
  protocolProcedures?: ReceptionPageProps['protocolProcedures'];
  preselectedPatient?: ReceptionPreselectedPatient | null;
  activeEncounter?: ReceptionEncounterItem | null;
  rosterStatus: string;
  rosterFilter: string;
  weekLabel: string;
  days: ReceptionWeekDay[];
  restricted: string[];
}): ReactNode {
  const can = useCan();
  const canCheckIn = can(permissions.encounter.create);
  const canRegister = can(permissions.patient.create);
  const showArrivalActions = canCheckIn || canRegister;
  // Registration entry carries the board context so the return redirect
  // restores the exact view (date/status/filter) with the new patient.
  const registerHref = (() => {
    const params = new URLSearchParams({ return_to_reception: '1', date, status: rosterStatus });
    if (rosterFilter !== '') params.set('filter', rosterFilter);
    return `${routes.workspaces.patientRegister}?${params.toString()}`;
  })();
  // Registration return flow (?patient_id=…): the new patient arrives
  // preselected and check-in-ready. URL-driven, so back/forward and refresh
  // behave — no global state, no remount tricks.
  const initialWalkIn: CheckInTarget | null = preselectedPatient && canCheckIn && !activeEncounter
    ? {
      patientId: preselectedPatient.patientId,
      displayName: preselectedPatient.displayName,
      patientNumber: preselectedPatient.patientNumber,
    }
    : null;
  /**
   * Single active workspace for the board: walk-in search vs roster row
   * check-in share one union, so opening one auto-closes the other.
   * `key` on each panel below remounts the form on switch (React key power).
   */
  type BoardWorkspace =
    | { kind: 'walkin'; initial: CheckInTarget | null }
    | { kind: 'roster'; target: CheckInTarget };
  const [workspace, setWorkspace] = useState<BoardWorkspace | null>(
    initialWalkIn ? { kind: 'walkin', initial: initialWalkIn } : null,
  );
  /**
   * Dirty-discard guard (same as the chart): the open panel reports dirty;
   * switching away pauses in `pendingSwitch` until the modal resolves.
   */
  const {
    setDirty: setWorkspaceDirty,
    pendingSwitch,
    requestSwitch,
    confirmPendingSwitch,
    cancelPendingSwitch,
  } = useWorkspaceGuard();
  const [query, setQuery] = useState(rosterFilter);
  const [navigating, setNavigating] = useState(false);
  const rows = toRows(encounters, appointments);
  // Facility-wall-clock day comparison, same expression as the Reception
  // page: the board's day buckets are facility days server-side.
  const isToday = date === dayInZone(timezone);
  const rosterDateLabel = formatDobDate(date) ?? date;
  const rosterTitle = isToday ? "Today's roster" : `Roster for ${rosterDateLabel}`;

  // Keep the box in sync when the URL filter changes elsewhere
  // (week strip, back/forward). Skipped while the box is focused so a
  // mid-typing server echo never overwrites keystrokes or drops focus.
  const prevFilterRef = useRef(rosterFilter);
  useEffect(() => {
    if (prevFilterRef.current === rosterFilter) return;
    prevFilterRef.current = rosterFilter;
    const active = typeof document === 'undefined' ? null : document.activeElement;
    const box = typeof document === 'undefined' ? null : document.getElementById('front-desk-roster-search');
    if (active !== null && box !== null && active === box) return;
    setQuery(rosterFilter);
  }, [rosterFilter]);

  const debouncedQuery = useDebouncedValue(query, 400);
  const lastSentRef = useRef(rosterFilter);

  const visitRoster = useCallback((filter: string, status = rosterStatus): void => {
    lastSentRef.current = filter;
    router.get(routes.workspaces.reception, { date, status, filter }, {
      preserveScroll: true,
      preserveState: true,
    });
  }, [date, rosterStatus]);

  // Live server search: typing filters without Enter, clearing the box
  // immediately restores the full roster — no second Enter needed.
  useEffect(() => {
    const trimmed = debouncedQuery.trim();
    if (trimmed === lastSentRef.current) return;
    visitRoster(trimmed);
  }, [debouncedQuery, visitRoster]);

  useEffect(() => {
    const stopStart = router.on('start', () => setNavigating(true));
    const stopFinish = router.on('finish', () => setNavigating(false));
    return () => {
      stopStart();
      stopFinish();
    };
  }, []);

  function clearSearch(): void {
    setQuery('');
    visitRoster('');
  }

  function clearRosterFilters(): void {
    setQuery('');
    lastSentRef.current = '';
    router.get(routes.workspaces.reception, { date, status: 'ALL', filter: '' }, {
      preserveScroll: true,
      preserveState: true,
    });
  }

  function applyRosterStatuses(statuses: string[]): void {
    visitRoster(query.trim(), statuses.join(',') || 'ALL');
  }

  function openWorkspace(next: BoardWorkspace | null): void {
    setWorkspaceDirty(false);
    setWorkspace(next);
  }

  const renderedRows = rows.map((row) => row.kind === 'encounter'
    ? EncounterTableRow({ item: row.item, timezone })
    : AppointmentTableRow({
      item: row.item,
      timezone,
      canCheckIn,
      onCheckIn: (target) => requestSwitch(() => openWorkspace({ kind: 'roster', target })),
    }));

  return (
    <div className="front-desk-board">
      <PageHeader
        title="Front Desk"
        description={`Manage ${isToday ? "today's" : `${rosterDateLabel}'s`} arrivals, patient check-ins, and appointments.`}
        actions={showArrivalActions ? (
          <FrontDeskQuickActions
            availableQueues={availableQueues}
            protocolProcedures={protocolProcedures}
            registerHref={registerHref}
            initialTarget={initialWalkIn}
            walkinOpen={workspace?.kind === 'walkin'}
            onToggleWalkin={() => requestSwitch(() => openWorkspace(
              workspace?.kind === 'walkin' ? null : { kind: 'walkin', initial: initialWalkIn },
            ))}
            onCloseWalkin={() => requestSwitch(() => openWorkspace(null))}
            onDirtyChange={setWorkspaceDirty}
          />
        ) : null}
      />
      {preselectedPatient && activeEncounter && canCheckIn ? (
        <InlineNotification
          kind="info"
          title={`${preselectedPatient.displayName} already has an active encounter`}
          subtitle={`${activeEncounter.encounterNumber} · check-in is not needed.`}
          lowContrast
        />
      ) : null}
      <MetricsStrip metrics={metrics} isToday={isToday} />
      <WeekStrip days={days} weekLabel={weekLabel} rosterStatus={rosterStatus} rosterFilter={rosterFilter} />

      {restricted.length > 0 ? (
        <p role="status" className="front-desk-board__restricted">
          Some sections are restricted for your role and show without details.
        </p>
      ) : null}

      <FrontDeskRosterTable
        rows={renderedRows}
        title={rosterTitle}
        isToday={isToday}
        dateLabel={rosterDateLabel}
        status={rosterStatus}
        filter={rosterFilter}
        query={query}
        navigating={navigating}
        showRegisterAction={query.trim() !== '' && canRegister}
        onQueryChange={setQuery}
        onClearSearch={clearSearch}
        onApplyStatuses={applyRosterStatuses}
        onResetFilters={clearRosterFilters}
        onRegister={() => router.visit(registerHref)}
      />

      {workspace?.kind === 'roster' && canCheckIn ? (
        <CheckInAction
          key={`roster:${workspace.target.patientId}:${workspace.target.appointmentId ?? 'walk-in'}`}
          target={workspace.target}
          availableQueues={availableQueues}
          protocolProcedures={protocolProcedures}
          offsetInlineEnd="0px"
          onClose={() => requestSwitch(() => openWorkspace(null))}
          onDirtyChange={setWorkspaceDirty}
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
