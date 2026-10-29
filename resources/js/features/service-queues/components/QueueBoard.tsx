import {
  Accordion,
  AccordionItem,
  Button,
  ComboButton,
  InlineNotification,
  MenuItem,
  Modal,
  Select,
  SelectItem,
  TabPanel,
  TabPanels,
  Tabs,
  Tag,
  TextInput,
} from '@carbon/react';
import type { ReactNode } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { routes } from '@/app/routes';
import { CarbonDataTable, ContainedTabList, EmptyState, IconTab, StatisticsCards, Status } from '@/components/ui';
import { usePersistentTab } from '@/hooks/usePersistentTab';
import { Notification, Pending, Renew, Task, Time, WarningAlt } from '@/icons';
import { permissions, useCan } from '@/lib/authorization';
import type { QueueBoardEntry, QueueBoardProps } from '../types/queue-board';

const QUEUE_HEADERS = [
  { key: 'queue', header: 'Queue' },
  { key: 'service', header: 'Service' },
  { key: 'operatingStatus', header: 'Status' },
  { key: 'waiting', header: 'Waiting' },
  { key: 'attending', header: 'In service' },
  { key: 'averageWait', header: 'Average wait' },
  { key: 'longestWait', header: 'Longest wait' },
];

const WAITING_HEADERS = [
  { key: 'ticketNumber', header: 'Ticket' },
  { key: 'patient', header: 'Patient' },
  { key: 'queue', header: 'Queue' },
  { key: 'priority', header: 'Priority' },
  { key: 'wait', header: 'Wait' },
  { key: 'action', header: 'Action' },
];

const CALLED_HEADERS = [
  { key: 'ticketNumber', header: 'Ticket' },
  { key: 'patient', header: 'Patient' },
  { key: 'queue', header: 'Queue' },
  { key: 'priority', header: 'Priority' },
  { key: 'room', header: 'Room' },
  { key: 'action', header: 'Action' },
];

const IN_SERVICE_HEADERS = [
  { key: 'ticketNumber', header: 'Ticket' },
  { key: 'patient', header: 'Patient' },
  { key: 'queue', header: 'Queue' },
  { key: 'priority', header: 'Priority' },
  { key: 'room', header: 'Room' },
  { key: 'action', header: 'Action' },
];

const ON_HOLD_HEADERS = [
  { key: 'ticketNumber', header: 'Ticket' },
  { key: 'patient', header: 'Patient' },
  { key: 'queue', header: 'Queue' },
  { key: 'priority', header: 'Priority' },
  { key: 'holdReason', header: 'Hold reason' },
  { key: 'action', header: 'Action' },
];

const RECALLABLE_HEADERS = [
  { key: 'ticketNumber', header: 'Ticket' },
  { key: 'patient', header: 'Patient' },
  { key: 'queue', header: 'Queue' },
  { key: 'priority', header: 'Priority' },
  { key: 'status', header: 'Status' },
  { key: 'action', header: 'Action' },
];

const BOARD_REFRESH_INTERVAL = 15_000;
const QUEUE_BOARD_TABS = ['waiting', 'called', 'in-service', 'on-hold', 'needs-recall'] as const;
const BOARD_REFRESH_PROPS = [
  'asOf',
  'totals',
  'queues',
  'waitingEntries',
  'calledEntries',
  'inServiceEntries',
  'onHoldEntries',
  'recallableEntries',
  'rooms',
  'transferQueues',
  'queueRoomAssignments',
  'myQueueRoomAssignments',
];

type PriorityTag = 'gray' | 'purple' | 'warm-gray' | 'red';
type TicketAction = 'call' | 'hold' | 'release' | 'skip' | 'recall' | 'transfer';
type ActionDialog = { action: TicketAction; entry: QueueBoardEntry };

function priorityTag(priority: string): PriorityTag {
  switch (priority) {
    case 'EMERGENCY':
      return 'red';
    case 'URGENT':
      return 'purple';
    case 'PRIORITY':
      return 'warm-gray';
    default:
      return 'gray';
  }
}

function operatingStatus(status: string): ReactNode {
  switch (status) {
    case 'ACTIVE':
      return <Status kind="active">Active</Status>;
    case 'PAUSED':
      return <Status kind="warning">Paused</Status>;
    case 'CLOSED':
      return <Status kind="inactive">Closed</Status>;
    default:
      return <Status kind="info">{status.replaceAll('_', ' ')}</Status>;
  }
}

function formatMinutes(value: number | null): string {
  if (value === null) return '—';

  const minutes = Math.max(0, Math.round(value));
  if (minutes < 60) return `${minutes} min`;

  const hours = Math.floor(minutes / 60);
  const remainingMinutes = minutes % 60;
  return remainingMinutes === 0 ? `${hours} hr` : `${hours} hr ${remainingMinutes} min`;
}

function PatientName({ entry }: { entry: QueueBoardEntry }): ReactNode {
  return (
    <span>
      <span className="service-queues__patient-name">{entry.patientName}</span>
      <span className="service-queues__patient-number">
        {entry.patientNumber}
      </span>
    </span>
  );
}

function QueueEntriesTable({
  entries,
  kind,
  rooms,
  transferQueues,
  queueRoomAssignments,
  myQueueRoomAssignments,
}: {
  entries: QueueBoardEntry[];
  kind: 'waiting' | 'called' | 'in-service' | 'on-hold' | 'recallable';
  rooms: QueueBoardProps['rooms'];
  transferQueues: QueueBoardProps['transferQueues'];
  queueRoomAssignments: QueueBoardProps['queueRoomAssignments'];
  myQueueRoomAssignments: NonNullable<QueueBoardProps['myQueueRoomAssignments']>;
}): ReactNode {
  const can = useCan();
  const canCall = can(permissions.queue.ticketCall);
  const canService = can(permissions.queue.ticketService);
  const canHold = can(permissions.queue.ticketHold);
  const canSkip = can(permissions.queue.ticketSkip) || canCall;
  const canTransfer = can(permissions.queue.ticketTransfer);
  const headers = kind === 'waiting'
    ? WAITING_HEADERS
    : kind === 'called'
      ? CALLED_HEADERS
      : kind === 'on-hold'
        ? ON_HOLD_HEADERS
        : kind === 'recallable'
          ? RECALLABLE_HEADERS
          : IN_SERVICE_HEADERS;
  const [actionDialog, setActionDialog] = useState<ActionDialog | null>(null);
  const [selectedRoom, setSelectedRoom] = useState('');
  const [holdReason, setHoldReason] = useState('');
  const [holdProvenance, setHoldProvenance] = useState('');
  const [clearanceProvenance, setClearanceProvenance] = useState('');
  const [skipReason, setSkipReason] = useState('');
  const [transferDestination, setTransferDestination] = useState('');
  const [transferReason, setTransferReason] = useState('');
  const [busyEntryId, setBusyEntryId] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const roomNames = new Map(rooms.map((room) => [room.id, room.name]));
  const mappedRoomIds = new Map(
    queueRoomAssignments.map((assignment) => [assignment.queueId, assignment.roomIds]),
  );
  const mappedAssignedRoomIds = new Map(
    myQueueRoomAssignments.map((assignment) => [assignment.queueId, assignment.roomIds]),
  );
  const entryById = new Map(entries.map((entry) => [entry.id, entry]));
  const configuredRooms = (entry: QueueBoardEntry) => {
    const roomIds = mappedRoomIds.get(entry.queueId) ?? [];
    return rooms.filter((room) => roomIds.includes(room.id));
  };
  const eligibleRooms = (entry: QueueBoardEntry) => {
    const assignedRoomIds = new Set(mappedAssignedRoomIds.get(entry.queueId) ?? []);
    return configuredRooms(entry).filter((room) => assignedRoomIds.has(room.id));
  };
  const rows = entries.map((entry) => ({
    id: entry.id,
    ticketNumber: entry.ticketNumber,
    patient: `${entry.patientName} ${entry.patientNumber}`,
    queue: entry.queue,
    priority: entry.priority,
    wait: formatMinutes(entry.waitMinutes),
    room: entry.calledRoomId ? roomNames.get(entry.calledRoomId) ?? '—' : '—',
    action: '',
  }));
  const title = kind === 'waiting'
    ? 'Waiting'
    : kind === 'called'
      ? 'Called'
      : kind === 'on-hold'
        ? 'On Hold'
        : kind === 'recallable'
          ? 'Needs Recall'
        : 'In Service';
  const showRoutingAlert = (kind === 'waiting' || kind === 'recallable') &&
    entries.some((entry) => configuredRooms(entry).length === 0);
  const hasRoomMismatch = kind === 'called' && entries.some(
    (entry) => !entry.calledRoomId || !configuredRooms(entry).some((room) => room.id === entry.calledRoomId),
  );

  function returnTo(): string {
    if (typeof window === 'undefined') return routes.workspaces.serviceQueues;
    return `${window.location.pathname}${window.location.search}`;
  }

  function postAction(entry: QueueBoardEntry, action: 'start' | 'complete'): void {
    setActionError(null);
    setBusyEntryId(entry.id);
    router.post(
      action === 'start'
        ? routes.workspaces.ticketStart(entry.id)
        : routes.workspaces.ticketComplete(entry.id),
      { return_to: returnTo() },
      {
        preserveScroll: true,
        onError: (errors) => setActionError(errors[action] ?? 'The action could not be completed.'),
        onFinish: () => setBusyEntryId(null),
      },
    );
  }

  function callSelectedEntry(): void {
    if (!actionDialog || selectedRoom === '') return;
    setActionError(null);
    setBusyEntryId(actionDialog.entry.id);
    router.post(
      routes.workspaces.ticketCall(actionDialog.entry.id),
      { room: selectedRoom, return_to: returnTo() },
      {
        preserveScroll: true,
        onSuccess: closeActionDialog,
        onError: (errors) => setActionError(errors.call ?? errors.room ?? 'The call could not be completed.'),
        onFinish: () => setBusyEntryId(null),
      },
    );
  }

  function submitDialogAction(): void {
    if (!actionDialog) return;
    const { action, entry } = actionDialog;
    if (action === 'call') {
      callSelectedEntry();
      return;
    }
    if (action === 'recall') {
      setActionError(null);
      setBusyEntryId(entry.id);
      router.post(
        routes.workspaces.ticketRecall(entry.id),
        {
          ...(selectedRoom ? { room: selectedRoom } : {}),
          return_to: returnTo(),
        },
        {
          preserveScroll: true,
          onSuccess: closeActionDialog,
          onError: (errors) => setActionError(errors.recall ?? errors.room ?? 'The ticket could not be recalled.'),
          onFinish: () => setBusyEntryId(null),
        },
      );
      return;
    }

    const data = action === 'hold'
      ? {
          hold_reason: holdReason,
          hold_provenance: holdProvenance.trim(),
          return_to: returnTo(),
        }
      : action === 'release'
        ? { clearance_provenance: clearanceProvenance.trim(), return_to: returnTo() }
        : action === 'transfer'
          ? {
              destination_queue_id: transferDestination,
              reason: transferReason.trim(),
              return_to: returnTo(),
            }
          : { reason: skipReason.trim(), return_to: returnTo() };
    const url = action === 'hold'
      ? routes.workspaces.ticketHold(entry.id)
      : action === 'release'
        ? routes.workspaces.ticketRelease(entry.id)
        : action === 'transfer'
          ? routes.workspaces.ticketTransfer(entry.id)
          : routes.workspaces.ticketSkip(entry.id);

    setActionError(null);
    setBusyEntryId(entry.id);
    router.post(url, data, {
      preserveScroll: true,
      onSuccess: closeActionDialog,
      onError: (errors) => {
        setActionError(
          errors[action] ??
            errors.hold_reason ??
            errors.hold_provenance ??
            errors.clearance_provenance ??
            errors.destination_queue_id ??
            errors.transfer ??
            'The action could not be completed.',
        );
      },
      onFinish: () => setBusyEntryId(null),
    });
  }

  function closeActionDialog(): void {
    setActionDialog(null);
    setSelectedRoom('');
    setHoldReason('');
    setHoldProvenance('');
    setClearanceProvenance('');
    setSkipReason('');
    setTransferDestination('');
    setTransferReason('');
    setActionError(null);
  }

  function openActionDialog(action: TicketAction, entry: QueueBoardEntry): void {
    setSelectedRoom('');
    setHoldReason('');
    setHoldProvenance('');
    setClearanceProvenance('');
    setSkipReason('');
    setTransferDestination('');
    setTransferReason('');
    setActionError(null);
    setActionDialog({ action, entry });
  }

  const activeDialogAction = actionDialog?.action;
  const dialogCanSubmit = activeDialogAction === 'call'
    ? selectedRoom !== ''
    : activeDialogAction === 'hold'
      ? holdReason !== '' && holdProvenance.trim() !== ''
      : activeDialogAction === 'release'
        ? clearanceProvenance.trim() !== ''
        : activeDialogAction === 'transfer'
          ? transferDestination !== ''
          : activeDialogAction === 'recall'
            ? selectedRoom !== '' || Boolean(
                actionDialog?.entry.calledRoomId &&
                eligibleRooms(actionDialog.entry).some((room) => room.id === actionDialog.entry.calledRoomId),
              )
            : actionDialog !== null;
  const dialogTitle = actionDialog
    ? `${actionDialog.action === 'call'
        ? 'Call'
        : actionDialog.action === 'hold'
          ? 'Place on hold'
          : actionDialog.action === 'release'
            ? 'Release hold'
              : actionDialog.action === 'recall'
                ? 'Recall'
                : actionDialog.action === 'transfer'
                  ? 'Transfer'
                  : 'Skip'} ticket ${actionDialog.entry.ticketNumber}`
    : 'Ticket action';

  function renderActions(entry: QueueBoardEntry): ReactNode {
    const rowActions: Array<{
      id: TicketAction | 'start' | 'complete' | 'procedure';
      label: string;
      onSelect: () => void;
    }> = [];
    const hasTransferDestination = transferQueues.some(
      (queue) => queue.queueId !== entry.queueId,
    );

    if (kind === 'waiting' && canCall && eligibleRooms(entry).length > 0) {
      rowActions.push({
        id: 'call',
        label: 'Call',
        onSelect: () => openActionDialog('call', entry),
      });
    }
    if ((kind === 'waiting' || kind === 'called') && canHold) {
      rowActions.push({
        id: 'hold',
        label: 'Hold',
        onSelect: () => openActionDialog('hold', entry),
      });
    }
    if ((kind === 'waiting' || kind === 'called') && canSkip) {
      rowActions.push({
        id: 'skip',
        label: 'Skip',
        onSelect: () => openActionDialog('skip', entry),
      });
    }
    if (
      (kind === 'waiting' || kind === 'called' || kind === 'recallable') &&
      canTransfer &&
      hasTransferDestination
    ) {
      rowActions.push({
        id: 'transfer',
        label: 'Transfer',
        onSelect: () => openActionDialog('transfer', entry),
      });
    }
    const calledRoomMatchesQueue = Boolean(
      entry.calledRoomId &&
      eligibleRooms(entry).some((room) => room.id === entry.calledRoomId),
    );
    if (
      kind === 'called'
      && canService
      && calledRoomMatchesQueue
      && entry.serviceCapability !== 'TRIAGE'
    ) {
      rowActions.unshift({
        id: 'start',
        label: busyEntryId === entry.id ? 'Starting…' : 'Start service',
        onSelect: () => postAction(entry, 'start'),
      });
    }
    if (kind === 'recallable' && canCall && eligibleRooms(entry).length > 0) {
      rowActions.unshift({
        id: 'recall',
        label: 'Recall',
        onSelect: () => openActionDialog('recall', entry),
      });
    }
    if (kind === 'on-hold' && canHold) {
      rowActions.push({
        id: 'release',
        label: 'Release hold',
        onSelect: () => openActionDialog('release', entry),
      });
    }
    if (
      kind === 'in-service'
      && canService
      && entry.calledRoomId !== null
      && eligibleRooms(entry).some((room) => room.id === entry.calledRoomId)
    ) {
      rowActions.push({
        id: 'complete',
        label: busyEntryId === entry.id ? 'Completing…' : 'Complete',
        onSelect: () => postAction(entry, 'complete'),
      });
    }
    if (
      (kind === 'called' || kind === 'in-service')
      && entry.orderType === 'PROCEDURE_BOOKING'
      && entry.sourceOrderRef
      && can(permissions.clinical.proceduresRead)
    ) {
      rowActions.unshift({
        id: 'procedure',
        label: 'Open procedure',
        onSelect: () => router.visit(routes.workspaces.procedureWork(entry.id)),
      });
    }

    const primaryAction = rowActions[0];
    return (
      <div className="service-queues__row-actions">
        {primaryAction && rowActions.length > 1 ? (
          <ComboButton
            label={primaryAction.label}
            size="sm"
            disabled={busyEntryId !== null}
            onClick={primaryAction.onSelect}
          >
            {rowActions.slice(1).map((action) => (
              <MenuItem
                key={action.id}
                label={action.label}
                disabled={busyEntryId !== null}
                onClick={action.onSelect}
              />
            ))}
          </ComboButton>
        ) : primaryAction ? (
          <Button
            kind="tertiary"
            size="sm"
            disabled={busyEntryId !== null}
            onClick={primaryAction.onSelect}
          >
            {primaryAction.label}
          </Button>
        ) : null}
      </div>
    );
  }

  return (
    <>
      {showRoutingAlert || hasRoomMismatch ? (
        <InlineNotification
          kind="warning"
          title={hasRoomMismatch
            ? 'A called ticket is assigned to a room that does not match its queue'
            : kind === 'waiting'
              ? 'Room routing is not configured for every waiting queue'
              : 'Room routing is not configured for every recallable queue'}
          subtitle={hasRoomMismatch
            ? 'Do not start service until the room is corrected or the ticket is transferred to the appropriate queue.'
            : 'A queue administrator must assign rooms before tickets in those queues can be called.'}
          lowContrast
          className="service-queues__routing-alert"
        />
      ) : null}
      <CarbonDataTable
        rows={rows}
        headers={headers}
        title={`${title} · ${entries.length}`}
        ariaLabel={
          kind === 'waiting'
            ? 'Waiting patients'
            : kind === 'called'
              ? 'Called patients'
              : kind === 'on-hold'
                ? 'Tickets on hold'
                : kind === 'recallable'
                  ? 'Tickets needing recall'
                  : 'Patients in service'
        }
        emptyState={(
          <EmptyState
            title={
              kind === 'waiting'
                ? 'No patients are waiting'
                : kind === 'called'
                  ? 'No patients have been called'
                  : kind === 'on-hold'
                    ? 'No tickets are on hold'
                    : kind === 'recallable'
                      ? 'No tickets need recall'
                      : 'No patients are in service'
            }
            description="There are no matching queue entries to display."
          />
        )}
        headerClassName={(header) => header.key === 'action' ? 'service-queues__action-column' : undefined}
        cellClassName={(cell) => cell.info.header === 'action' ? 'service-queues__action-column' : undefined}
        renderCell={(cell, row) => {
          const entry = entryById.get(row.id);
          if (!entry) return cell.value;
          if (cell.info.header === 'ticketNumber') return entry.ticketNumber;
          if (cell.info.header === 'patient') return <PatientName entry={entry} />;
          if (cell.info.header === 'queue') return entry.queue;
          if (cell.info.header === 'priority') {
            return (
              <Tag type={priorityTag(entry.priority)}>
                {entry.priority.replaceAll('_', ' ')}
              </Tag>
            );
          }
          if (cell.info.header === 'wait') return formatMinutes(entry.waitMinutes);
          if (cell.info.header === 'holdReason') return entry.holdReason?.replaceAll('_', ' ') ?? '—';
          if (cell.info.header === 'status') return entry.status.replaceAll('_', ' ');
          if (cell.info.header === 'room') {
            return entry.calledRoomId ? roomNames.get(entry.calledRoomId) ?? '—' : '—';
          }
          if (cell.info.header === 'action') return renderActions(entry);
          return cell.value;
        }}
      />
      {actionError && !actionDialog ? (
        <div role="alert" className="service-queues__action-error">
          <InlineNotification kind="error" title={actionError} lowContrast />
        </div>
      ) : null}
          <Modal
            open={actionDialog !== null}
            modalHeading={dialogTitle}
            primaryButtonText={
              busyEntryId === actionDialog?.entry.id
                ? 'Saving…'
                : activeDialogAction === 'call'
                  ? 'Call patient'
                  : activeDialogAction === 'hold'
                    ? 'Place on hold'
                    : activeDialogAction === 'release'
                      ? 'Release hold'
                      : activeDialogAction === 'recall'
                        ? 'Recall ticket'
                        : activeDialogAction === 'transfer'
                          ? 'Transfer ticket'
                          : 'Skip ticket'
            }
            secondaryButtonText="Cancel"
            primaryButtonDisabled={!dialogCanSubmit || busyEntryId !== null}
            onRequestClose={closeActionDialog}
            onRequestSubmit={submitDialogAction}
          >
            {actionDialog ? (
              <>
                <p>
                  {activeDialogAction === 'call'
                    ? `Call ${actionDialog.entry.patientName} (${actionDialog.entry.patientNumber}) to a room.`
                    : activeDialogAction === 'hold'
                      ? `Place ${actionDialog.entry.patientName}'s ticket on hold.`
                      : activeDialogAction === 'release'
                        ? `Release the hold for ${actionDialog.entry.patientName}'s ticket.`
                          : activeDialogAction === 'recall'
                            ? `Recall ${actionDialog.entry.patientName}'s ticket to the queue.`
                            : activeDialogAction === 'transfer'
                              ? `Move ${actionDialog.entry.patientName}'s ticket to another service queue.`
                              : `Skip ${actionDialog.entry.patientName}'s ticket in the queue.`}
                </p>
                {activeDialogAction === 'call' || activeDialogAction === 'recall' ? (
                  <Select
                      id={activeDialogAction === 'call' ? 'service-queues-call-room' : 'service-queues-recall-room'}
                      labelText={activeDialogAction === 'call' ? 'Room' : 'Room (optional)'}
                      value={selectedRoom}
                      onChange={(event) => setSelectedRoom(event.currentTarget.value)}
                  >
                      <SelectItem
                        value=""
                        text={
                          activeDialogAction === 'call' || !actionDialog.entry.calledRoomId
                            ? 'Select a room'
                            : 'Keep previous room'
                        }
                      />
                      {eligibleRooms(actionDialog.entry).map((room) => (
                        <SelectItem key={room.id} value={room.id} text={`${room.name} (${room.code})`} />
                      ))}
                  </Select>
                ) : null}
                {activeDialogAction === 'transfer' ? (
                  <>
                      <Select
                        id="service-queues-transfer-destination"
                        labelText="Destination queue"
                        value={transferDestination}
                        onChange={(event) => setTransferDestination(event.currentTarget.value)}
                      >
                        <SelectItem value="" text="Select a queue" />
                        {transferQueues
                          .filter((queue) => queue.queueId !== actionDialog.entry.queueId)
                          .map((queue) => (
                            <SelectItem key={queue.queueId} value={queue.queueId} text={queue.queue} />
                          ))}
                      </Select>
                      <TextInput
                        id="service-queues-transfer-reason"
                        labelText="Reason (optional)"
                        value={transferReason}
                        onChange={(event) => setTransferReason(event.currentTarget.value)}
                      />
                  </>
                ) : null}
                {activeDialogAction === 'hold' ? (
                  <>
                    <Select
                      id="service-queues-hold-reason"
                      labelText="Hold reason"
                      value={holdReason}
                      onChange={(event) => setHoldReason(event.currentTarget.value)}
                    >
                      <SelectItem value="" text="Select a reason" />
                      <SelectItem value="FINANCIAL_HOLD" text="Financial hold" />
                      <SelectItem value="CLINICAL_HOLD" text="Clinical hold" />
                      <SelectItem value="ADMINISTRATIVE_HOLD" text="Administrative hold" />
                    </Select>
                    <TextInput
                      id="service-queues-hold-provenance"
                      labelText="Reference / provenance"
                      value={holdProvenance}
                      onChange={(event) => setHoldProvenance(event.currentTarget.value)}
                      placeholder="e.g. triage note or billing reference"
                    />
                  </>
                ) : null}
                {activeDialogAction === 'release' ? (
                  <TextInput
                    id="service-queues-clearance-provenance"
                    labelText="Clearance reference"
                    value={clearanceProvenance}
                    onChange={(event) => setClearanceProvenance(event.currentTarget.value)}
                    placeholder="e.g. payment receipt or clinician clearance"
                  />
                ) : null}
                {activeDialogAction === 'skip' ? (
                  <TextInput
                    id="service-queues-skip-reason"
                    labelText="Reason (optional)"
                    value={skipReason}
                    onChange={(event) => setSkipReason(event.currentTarget.value)}
                  />
                ) : null}
                {actionError && actionDialog ? (
                  <div role="alert" className="service-queues__action-error">
                    <InlineNotification kind="error" title={actionError} lowContrast />
                  </div>
                ) : null}
              </>
            ) : null}
          </Modal>
    </>
  );
}

export function QueueBoard({
  asOf,
  totals,
  queues,
  waitingEntries,
  calledEntries,
  inServiceEntries,
  onHoldEntries,
  recallableEntries,
  rooms,
  transferQueues,
  queueRoomAssignments,
  myQueueRoomAssignments = [],
}: QueueBoardProps): ReactNode {
  const [selectedQueueId, setSelectedQueueId] = useState('');
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [selectedTab, setSelectedTab] = usePersistentTab('tab', QUEUE_BOARD_TABS);
  const refreshInFlight = useRef(false);
  const selectedQueue = queues.find((queue) => queue.queueId === selectedQueueId) ?? null;

  const refreshBoard = useCallback((): void => {
    if (refreshInFlight.current) return;
    refreshInFlight.current = true;
    setIsRefreshing(true);
    router.reload({
      only: BOARD_REFRESH_PROPS,
      onFinish: () => {
        refreshInFlight.current = false;
        setIsRefreshing(false);
      },
    });
  }, []);

  useEffect(() => {
    const interval = window.setInterval(() => {
      if (document.visibilityState === 'visible') refreshBoard();
    }, BOARD_REFRESH_INTERVAL);
    return () => window.clearInterval(interval);
  }, [refreshBoard]);

  const formattedAsOf = asOf
    ? new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
      }).format(new Date(asOf))
    : 'Unavailable';

  const visibleWaiting = selectedQueue
    ? waitingEntries.filter((entry) => entry.queueCode === selectedQueue.code)
    : waitingEntries;
  const visibleInService = selectedQueue
    ? inServiceEntries.filter((entry) => entry.queueCode === selectedQueue.code)
    : inServiceEntries;
  const visibleCalled = selectedQueue
    ? calledEntries.filter((entry) => entry.queueCode === selectedQueue.code)
    : calledEntries;
  const visibleOnHold = selectedQueue
    ? onHoldEntries.filter((entry) => entry.queueCode === selectedQueue.code)
    : onHoldEntries;
  const visibleRecallable = selectedQueue
    ? recallableEntries.filter((entry) => entry.queueCode === selectedQueue.code)
    : recallableEntries;
  const queueRows = queues.map((queue) => ({
    ...queue,
    id: queue.queueId,
    averageWait: queue.averageWaitMinutes,
    longestWait: queue.longestWaitMinutes,
  }));
  return (
    <div>
      <StatisticsCards
        label="Facility queue totals"
        cards={[
          {
            id: 'waiting',
            label: 'Waiting',
            value: waitingEntries.length,
            detail: (
              <>
                Average wait {formatMinutes(totals.averageWaitMinutes)}
                {totals.longestWaitPatient
                  ? ` · Longest ${formatMinutes(totals.longestWaitMinutes)} (${totals.longestWaitPatient})`
                  : ''}
              </>
            ),
          },
          {
            id: 'called',
            label: 'Called',
            value: calledEntries.length,
            detail: 'Patients called to a service room',
          },
          {
            id: 'in-service',
            label: 'In service',
            value: inServiceEntries.length,
            detail: 'Patients currently receiving service',
          },
          {
            id: 'on-hold',
            label: 'On hold',
            value: onHoldEntries.length,
            detail: 'Tickets waiting for hold clearance',
          },
          {
            id: 'needs-recall',
            label: 'Needs recall',
            value: recallableEntries.length,
            detail: 'Skipped or no-response tickets',
          },
        ]}
      />

      <section className="service-queues__overview" aria-label="Facility overview">
        <Accordion className="service-queues__overview-accordion" size="sm">
          <AccordionItem title="Facility overview">
            <CarbonDataTable
              rows={queueRows}
              headers={QUEUE_HEADERS}
              title="Facility overview"
              ariaLabel="Facility queues"
              emptyState={(
                <EmptyState
                  title="No queues are available"
                  description="There are no queues configured for this facility."
                />
              )}
              renderCell={(cell, row) => {
                const queue = queues.find((item) => item.queueId === row.id);
                if (!queue) return cell.value;
                if (cell.info.header === 'queue') {
                  return (
                    <>
                      <span style={{ display: 'block' }}>{queue.queue}</span>
                      <span>
                        {queue.code}
                      </span>
                    </>
                  );
                }
                if (cell.info.header === 'operatingStatus') return operatingStatus(queue.operatingStatus);
                if (cell.info.header === 'averageWait') return formatMinutes(queue.averageWaitMinutes);
                if (cell.info.header === 'longestWait') return formatMinutes(queue.longestWaitMinutes);
                return cell.value;
              }}
            />
          </AccordionItem>
        </Accordion>
      </section>

      <div className="service-queues__flow">
        <div className="service-queues__flow-heading">
          <h2>Patient flow</h2>
          <Select
            id="service-queues-filter"
            labelText="Filter patient lists by queue"
            value={selectedQueueId}
            onChange={(event) => setSelectedQueueId(event.currentTarget.value)}
            size="sm"
          >
            <SelectItem value="" text="All queues" />
            {queues.map((queue) => (
              <SelectItem
                key={queue.queueId}
                value={queue.queueId}
                text={`${queue.queue} (${queue.code})`}
              />
            ))}
          </Select>
        </div>
        <p className="service-queues__data-note">
          Lists show up to 200 entries; the waiting total also includes no-response tickets.
        </p>
        <div className="service-queues__tabs">
          <Tabs selectedIndex={selectedTab} onChange={({ selectedIndex }) => setSelectedTab(selectedIndex)}>
            <div className="service-queues__tab-bar">
              <ContainedTabList aria-label="Patient flow status" className="service-queues__tab-list">
                <IconTab icon={Time}>Waiting ({visibleWaiting.length})</IconTab>
                <IconTab icon={Notification}>Called ({visibleCalled.length})</IconTab>
                <IconTab icon={Task}>In Service ({visibleInService.length})</IconTab>
                <IconTab icon={Pending}>On Hold ({visibleOnHold.length})</IconTab>
                <IconTab icon={WarningAlt}>Needs Recall ({visibleRecallable.length})</IconTab>
              </ContainedTabList>
              <Button
                kind="ghost"
                size="sm"
                hasIconOnly
                renderIcon={Renew}
                tooltipPosition="left"
                iconDescription={
                  `${isRefreshing ? 'Refreshing' : 'Refresh'} queue board. ` +
                  `Last updated ${formattedAsOf}. Automatically refreshes every 15 seconds.`
                }
                disabled={isRefreshing}
                onClick={refreshBoard}
              />
            </div>
            <TabPanels>
              <TabPanel>
                <QueueEntriesTable
                  entries={visibleWaiting}
                  kind="waiting"
                  rooms={rooms}
                  transferQueues={transferQueues}
                  queueRoomAssignments={queueRoomAssignments}
                  myQueueRoomAssignments={myQueueRoomAssignments}
                />
              </TabPanel>
              <TabPanel>
                <QueueEntriesTable
                  entries={visibleCalled}
                  kind="called"
                  rooms={rooms}
                  transferQueues={transferQueues}
                  queueRoomAssignments={queueRoomAssignments}
                  myQueueRoomAssignments={myQueueRoomAssignments}
                />
              </TabPanel>
              <TabPanel>
                <QueueEntriesTable
                  entries={visibleInService}
                  kind="in-service"
                  rooms={rooms}
                  transferQueues={transferQueues}
                  queueRoomAssignments={queueRoomAssignments}
                  myQueueRoomAssignments={myQueueRoomAssignments}
                />
              </TabPanel>
              <TabPanel>
                <QueueEntriesTable
                  entries={visibleOnHold}
                  kind="on-hold"
                  rooms={rooms}
                  transferQueues={transferQueues}
                  queueRoomAssignments={queueRoomAssignments}
                  myQueueRoomAssignments={myQueueRoomAssignments}
                />
              </TabPanel>
              <TabPanel>
                <QueueEntriesTable
                  entries={visibleRecallable}
                  kind="recallable"
                  rooms={rooms}
                  transferQueues={transferQueues}
                  queueRoomAssignments={queueRoomAssignments}
                  myQueueRoomAssignments={myQueueRoomAssignments}
                />
              </TabPanel>
            </TabPanels>
          </Tabs>
        </div>
      </div>
    </div>
  );
}
