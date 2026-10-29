import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { QueueBoard } from './QueueBoard';
import { QueueRoomRoutingAction } from './QueueRoomRoutingAction';

const queueA = {
  queueId: 'queue-a',
  queue: 'General OPD',
  code: 'OPD',
  service: 'Consultation',
  location: null,
  waiting: 1,
  attending: 1,
  averageWaitMinutes: 18,
  longestWaitMinutes: 25,
  longestWaitPatient: 'Jane Doe',
  operatingStatus: 'ACTIVE',
};

const queueB = {
  queueId: 'queue-b',
  queue: 'Triage',
  code: 'TRI',
  service: 'Triage',
  location: null,
  waiting: 1,
  attending: 0,
  averageWaitMinutes: 7,
  longestWaitMinutes: 7,
  longestWaitPatient: 'John Smith',
  operatingStatus: 'PAUSED',
};

const props = {
  asOf: '2026-10-05T07:00:00+00:00',
  totals: {
    waiting: 2,
    inService: 1,
    averageWaitMinutes: 12.5,
    longestWaitMinutes: 25,
    longestWaitPatient: 'Jane Doe',
  },
  queues: [queueA, queueB],
  recallableEntries: [
    {
      id: 'entry-skipped',
      queueId: 'queue-a',
      ticketNumber: 'OPD-004',
      patientName: 'Daniel Mfaume',
      patientNumber: 'PAT-006',
      dateOfBirth: null,
      sex: 'M',
      queue: 'General OPD',
      queueCode: 'OPD',
      priority: 'ROUTINE',
      status: 'SKIPPED',
      holdReason: null,
      waitMinutes: 18,
      arrivedAt: '2026-10-05T06:42:00+00:00',
      calledRoomId: 'room-1',
    },
    {
      id: 'entry-no-response',
      queueId: 'queue-b',
      ticketNumber: 'TRI-003',
      patientName: 'Nuru Kweka',
      patientNumber: 'PAT-007',
      dateOfBirth: null,
      sex: 'F',
      queue: 'Triage',
      queueCode: 'TRI',
      priority: 'URGENT',
      status: 'NO_RESPONSE',
      holdReason: null,
      waitMinutes: 14,
      arrivedAt: '2026-10-05T06:46:00+00:00',
      calledRoomId: null,
    },
  ],
  calledEntries: [
    {
      id: 'entry-called',
      queueId: 'queue-a',
      ticketNumber: 'OPD-003',
      patientName: 'Asha Mushi',
      patientNumber: 'PAT-004',
      dateOfBirth: null,
      sex: 'F',
      queue: 'General OPD',
      queueCode: 'OPD',
      priority: 'ROUTINE',
      status: 'CALLED',
      holdReason: null,
      waitMinutes: 12,
      arrivedAt: '2026-10-05T06:48:00+00:00',
      calledRoomId: 'room-1',
    },
  ],
  waitingEntries: [
    {
      id: 'entry-a',
      queueId: 'queue-a',
      ticketNumber: 'OPD-001',
      patientName: 'Jane Doe',
      patientNumber: 'PAT-001',
      dateOfBirth: null,
      sex: 'F',
      queue: 'General OPD',
      queueCode: 'OPD',
      priority: 'ROUTINE',
      status: 'WAITING',
      holdReason: null,
      waitMinutes: 25,
      arrivedAt: '2026-10-05T06:35:00+00:00',
      calledRoomId: null,
    },
    {
      id: 'entry-b',
      queueId: 'queue-b',
      ticketNumber: 'TRI-001',
      patientName: 'John Smith',
      patientNumber: 'PAT-002',
      dateOfBirth: null,
      sex: 'M',
      queue: 'Triage',
      queueCode: 'TRI',
      priority: 'URGENT',
      status: 'WAITING',
      holdReason: null,
      waitMinutes: 7,
      arrivedAt: '2026-10-05T06:53:00+00:00',
      calledRoomId: null,
    },
  ],
  inServiceEntries: [
    {
      id: 'entry-c',
      queueId: 'queue-a',
      ticketNumber: 'OPD-002',
      patientName: 'Maria Joseph',
      patientNumber: 'PAT-003',
      dateOfBirth: null,
      sex: 'F',
      queue: 'General OPD',
      queueCode: 'OPD',
      priority: 'PRIORITY',
      status: 'IN_SERVICE',
      holdReason: null,
      waitMinutes: 30,
      arrivedAt: '2026-10-05T06:30:00+00:00',
      calledRoomId: 'room-1',
    },
  ],
  onHoldEntries: [
    {
      id: 'entry-held',
      queueId: 'queue-b',
      ticketNumber: 'TRI-002',
      patientName: 'Neema Said',
      patientNumber: 'PAT-005',
      dateOfBirth: null,
      sex: 'F',
      queue: 'Triage',
      queueCode: 'TRI',
      priority: 'ROUTINE',
      status: 'HOLD',
      holdReason: 'CLINICAL_HOLD',
      waitMinutes: 9,
      arrivedAt: '2026-10-05T06:51:00+00:00',
      calledRoomId: null,
    },
  ],
  rooms: [
    { id: 'room-1', name: 'Consultation 1', code: 'CONS-1' },
    { id: 'room-2', name: 'Triage 1', code: 'TRI-1' },
  ],
  transferQueues: [
    { queueId: 'queue-a', queue: 'General OPD' },
    { queueId: 'queue-b', queue: 'Triage' },
  ],
  queueRoomAssignments: [
    { queueId: 'queue-a', queue: 'General OPD', code: 'OPD', roomIds: ['room-1'] },
    { queueId: 'queue-b', queue: 'Triage', code: 'TRI', roomIds: ['room-2'] },
  ],
  myQueueRoomAssignments: [
    { queueId: 'queue-a', roomIds: ['room-1'] },
    { queueId: 'queue-b', roomIds: ['room-2'] },
  ],
};

const actionState = vi.hoisted(() => ({
  canCall: true,
  canService: true,
  canHold: true,
  canSkip: true,
  canTransfer: true,
  canManageRouting: true,
  post: vi.fn(),
  put: vi.fn(),
  reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: { post: actionState.post, put: actionState.put, reload: actionState.reload },
}));

vi.mock('@/lib/authorization', () => ({
  permissions: {
    queue: {
      ticketCall: 'queue.ticket.call',
      ticketService: 'queue.ticket.service',
      ticketHold: 'queue.ticket.hold',
      ticketSkip: 'queue.ticket.skip',
      ticketTransfer: 'queue.ticket.transfer',
      definitionManage: 'queue.definition.manage',
    },
  },
  useCan: () => (permission: string) => {
    switch (permission) {
      case 'queue.ticket.call':
        return actionState.canCall;
      case 'queue.ticket.service':
        return actionState.canService;
      case 'queue.ticket.hold':
        return actionState.canHold;
      case 'queue.ticket.skip':
        return actionState.canSkip;
      case 'queue.ticket.transfer':
        return actionState.canTransfer;
      case 'queue.definition.manage':
        return actionState.canManageRouting;
      default:
        return false;
    }
  },
}));

beforeEach(() => {
  actionState.canCall = true;
  actionState.canService = true;
  actionState.canHold = true;
  actionState.canSkip = true;
  actionState.canTransfer = true;
  actionState.canManageRouting = true;
  actionState.post.mockReset();
  actionState.put.mockReset();
  actionState.reload.mockReset();
  actionState.post.mockImplementation((_url, _data, options) => {
    options?.onSuccess?.();
    options?.onFinish?.();
  });
  actionState.put.mockImplementation((_url, _data, options) => options?.onSuccess?.());
  actionState.reload.mockImplementation((options) => options?.onFinish?.());
  globalThis.ResizeObserver ??= class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    value: vi.fn().mockImplementation((media: string) => ({
      matches: false,
      media,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  });
  window.history.replaceState({}, '', '/workspaces/service-queues');
});

afterEach(() => {
  vi.useRealTimers();
});

describe('QueueBoard', () => {
  it('renders facility overview and all patient-flow groups', () => {
    render(<QueueBoard {...props} />);

    const statistics = screen.getByRole('region', { name: 'Facility queue totals' });
    expect(within(statistics).getByText('Waiting')).toBeInTheDocument();
    expect(within(statistics).getByText('Called')).toBeInTheDocument();
    expect(within(statistics).getByText('In service')).toBeInTheDocument();
    expect(within(statistics).getByText('On hold')).toBeInTheDocument();
    expect(within(statistics).getByText('Needs recall')).toBeInTheDocument();
    expect(statistics.querySelectorAll('.afy-stat')).toHaveLength(5);
    const facilityOverview = screen.getByRole('button', { name: 'Facility overview' });
    expect(facilityOverview).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(facilityOverview);
    expect(facilityOverview).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('table', { name: 'Facility overview' })).toHaveAttribute('aria-label', 'Facility queues');
    expect(screen.getByRole('tab', { name: 'Waiting (2)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Called (1)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'In Service (1)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'On Hold (1)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Needs Recall (2)' })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Waiting · 2' })).toBeInTheDocument();
    expect(screen.getByText('Jane Doe')).toBeInTheDocument();
    expect(screen.getByText('John Smith')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'In Service (1)' }));
    expect(screen.getByRole('table', { name: 'In Service · 1' })).toBeInTheDocument();
    expect(screen.getByText('Maria Joseph')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'Called (1)' }));
    expect(screen.getByRole('table', { name: 'Called · 1' })).toBeInTheDocument();
    expect(screen.getByText('Asha Mushi')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'On Hold (1)' }));
    expect(screen.getByText('Neema Said')).toBeInTheDocument();
    expect(screen.getByText('CLINICAL HOLD')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'Needs Recall (2)' }));
    expect(screen.getByText('Daniel Mfaume')).toBeInTheDocument();
    expect(screen.getByText('Nuru Kweka')).toBeInTheDocument();
    expect(screen.getByText(/Lists show up to 200 entries; the waiting total also includes no-response tickets/)).toBeInTheDocument();
  });

  it('restores the selected patient-flow tab from the URL', () => {
    window.history.replaceState({}, '', '/workspaces/service-queues?tab=on-hold');
    render(<QueueBoard {...props} />);

    expect(screen.getByRole('tab', { name: 'On Hold (1)' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('table', { name: 'On Hold · 1' })).toBeInTheDocument();
  });

  describe('QueueRoomRoutingAction', () => {
    it('shows the queue room configuration action for queue administrators', () => {
      render(
        <QueueRoomRoutingAction
          rooms={props.rooms}
          queueRoomAssignments={props.queueRoomAssignments}
        />,
      );

      fireEvent.click(screen.getByRole('button', { name: 'Configure queue rooms' }));
      expect(screen.getByRole('dialog', { name: 'Configure queue rooms' })).toBeInTheDocument();
      expect(screen.getByLabelText('Queue')).toHaveValue('queue-a');
      expect(screen.getByPlaceholderText('Search rooms by name or code')).toBeInTheDocument();
    });

    it('hides the configuration action without queue definition permission', () => {
      actionState.canManageRouting = false;
      render(
        <QueueRoomRoutingAction
          rooms={props.rooms}
          queueRoomAssignments={props.queueRoomAssignments}
        />,
      );

      expect(screen.queryByRole('button', { name: 'Configure queue rooms' })).not.toBeInTheDocument();
    });
  });

  it('places an icon refresh button beside the tabs and reloads the board props on demand', () => {
    render(<QueueBoard {...props} />);

    const refreshButton = screen.getByRole('button', {
      name: /Refresh queue board\. Last updated .*\. Automatically refreshes every 15 seconds\./,
    });
    expect(refreshButton).toHaveClass('cds--btn--icon-only');
    expect(refreshButton.closest('.service-queues__tab-bar')).toBeInTheDocument();
    fireEvent.click(refreshButton);

    expect(actionState.reload).toHaveBeenCalledWith(
      expect.objectContaining({
        only: [
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
        ],
      }),
    );
  });

  it('polls for fresh board props every 15 seconds while visible', () => {
    vi.useFakeTimers();
    render(<QueueBoard {...props} />);

    act(() => {
      vi.advanceTimersByTime(15_000);
    });

    expect(actionState.reload).toHaveBeenCalledTimes(1);
  });

  it('filters waiting and in-service entries by the selected queue', () => {
    render(<QueueBoard {...props} />);

    fireEvent.change(screen.getByLabelText('Filter patient lists by queue'), {
      target: { value: 'queue-a' },
    });

    expect(screen.getByText('Jane Doe')).toBeInTheDocument();
    expect(screen.queryByText('John Smith')).not.toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Waiting (1)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Called (1)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'In Service (1)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'On Hold (0)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Needs Recall (1)' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'In Service (1)' }));
    expect(screen.getByText('Maria Joseph')).toBeInTheDocument();
  });

  it('shows clear empty states when queues and entries are absent', () => {
    render(
      <QueueBoard
        {...props}
        queues={[]}
        calledEntries={[]}
        waitingEntries={[]}
        inServiceEntries={[]}
        onHoldEntries={[]}
        recallableEntries={[]}
      />,
    );

    expect(screen.getByText('No queues are available')).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Waiting (0)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Called (0)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'On Hold (0)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Needs Recall (0)' })).toBeInTheDocument();
    expect(screen.getByText('No patients are waiting')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'In Service (0)' }));
    expect(screen.getByText('No patients are in service')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'On Hold (0)' }));
    expect(screen.getByText('No tickets are on hold')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'Needs Recall (0)' }));
    expect(screen.getByText('No tickets need recall')).toBeInTheDocument();
  });

  it('opens the room selection dialog and posts a call to the selected room', () => {
    render(<QueueBoard {...props} />);

    fireEvent.click(screen.getAllByRole('button', { name: 'Call' })[0]!);
    expect(screen.getByRole('dialog', { name: 'Call ticket OPD-001' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Consultation 1 (CONS-1)' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Triage 1 (TRI-1)' })).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Room'), { target: { value: 'room-1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Call patient' }));

    expect(actionState.post).toHaveBeenCalledWith(
      '/workspaces/service-queues/entries/entry-a/call',
      expect.objectContaining({ room: 'room-1', return_to: '/workspaces/service-queues' }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('does not offer call when a queue has no assigned rooms', () => {
    render(
      <QueueBoard
        {...props}
        queueRoomAssignments={props.queueRoomAssignments.map((assignment) => (
          assignment.queueId === 'queue-a' ? { ...assignment, roomIds: [] } : assignment
        ))}
      />,
    );

    fireEvent.change(screen.getByLabelText('Filter patient lists by queue'), {
      target: { value: 'queue-a' },
    });
    expect(screen.getByText('Room routing is not configured for every waiting queue')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Call' })).not.toBeInTheDocument();
  });

  it('does not offer call or start service without a personal room assignment', () => {
    render(<QueueBoard {...props} myQueueRoomAssignments={[]} />);

    expect(screen.queryByRole('button', { name: 'Call' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'Called (1)' }));
    expect(screen.queryByRole('button', { name: 'Start service' })).not.toBeInTheDocument();
  });

  it('blocks starting a called ticket when its room does not match its queue routing', () => {
    render(
      <QueueBoard
        {...props}
        queueRoomAssignments={props.queueRoomAssignments.map((assignment) => (
          assignment.queueId === 'queue-a' ? { ...assignment, roomIds: ['room-2'] } : assignment
        ))}
      />,
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Called (1)' }));

    expect(screen.getByText('A called ticket is assigned to a room that does not match its queue')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Start service' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Additional actions' })).toBeInTheDocument();
  });

  it('posts start and complete actions for called and in-service tickets', () => {
    render(<QueueBoard {...props} />);

    fireEvent.click(screen.getByRole('tab', { name: 'Called (1)' }));
    fireEvent.click(screen.getByRole('button', { name: 'Start service' }));
    expect(actionState.post).toHaveBeenCalledWith(
      '/workspaces/service-queues/entries/entry-called/start',
      { return_to: '/workspaces/service-queues?tab=called' },
      expect.objectContaining({ preserveScroll: true }),
    );

    fireEvent.click(screen.getByRole('tab', { name: 'In Service (1)' }));
    fireEvent.click(screen.getByRole('button', { name: 'Complete' }));
    expect(actionState.post).toHaveBeenCalledWith(
      '/workspaces/service-queues/entries/entry-c/complete',
      { return_to: '/workspaces/service-queues?tab=in-service' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('hides controls when the user lacks the corresponding permission', () => {
    actionState.canCall = false;
    actionState.canService = false;
    actionState.canHold = false;
    actionState.canSkip = false;
    actionState.canTransfer = false;
    render(<QueueBoard {...props} />);

    expect(screen.queryByRole('button', { name: 'Call' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Hold' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Skip' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'Called (1)' }));
    expect(screen.queryByRole('button', { name: 'Start service' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'In Service (1)' }));
    expect(screen.queryByRole('button', { name: 'Complete' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'On Hold (1)' }));
    expect(screen.queryByRole('button', { name: 'Release hold' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'Needs Recall (2)' }));
    expect(screen.queryByRole('button', { name: 'Recall' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Additional actions' })).not.toBeInTheDocument();
  });

  it('holds a ticket with a reason and provenance, then releases it with clearance provenance', () => {
    render(<QueueBoard {...props} />);

    fireEvent.click(screen.getAllByRole('button', { name: 'Additional actions' })[0]!);
    fireEvent.click(screen.getByRole('menuitem', { name: 'Hold' }));
    fireEvent.change(screen.getByLabelText('Hold reason'), {
      target: { value: 'CLINICAL_HOLD' },
    });
    fireEvent.change(screen.getByLabelText('Reference / provenance'), {
      target: { value: 'Triage note #12' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Place on hold' }));
    expect(actionState.post).toHaveBeenCalledWith(
      '/workspaces/service-queues/entries/entry-a/hold',
      {
        hold_reason: 'CLINICAL_HOLD',
        hold_provenance: 'Triage note #12',
        return_to: '/workspaces/service-queues',
      },
      expect.objectContaining({ preserveScroll: true }),
    );

    fireEvent.click(screen.getByRole('tab', { name: 'On Hold (1)' }));
    fireEvent.click(screen.getByRole('button', { name: 'Release hold' }));
    const dialog = screen.getByRole('dialog', { name: 'Release hold ticket TRI-002' });
    fireEvent.change(screen.getByLabelText('Clearance reference'), {
      target: { value: 'Clinician clearance #7' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Release hold' }));
    expect(actionState.post).toHaveBeenLastCalledWith(
      '/workspaces/service-queues/entries/entry-held/release',
      {
        clearance_provenance: 'Clinician clearance #7',
        return_to: '/workspaces/service-queues?tab=on-hold',
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('skips a ticket with an optional reason', () => {
    render(<QueueBoard {...props} />);

    fireEvent.click(screen.getAllByRole('button', { name: 'Additional actions' })[0]!);
    fireEvent.click(screen.getByRole('menuitem', { name: 'Skip' }));
    fireEvent.change(screen.getByLabelText('Reason (optional)'), {
      target: { value: 'Patient did not respond' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Skip ticket' }));

    expect(actionState.post).toHaveBeenCalledWith(
      '/workspaces/service-queues/entries/entry-a/skip',
      { reason: 'Patient did not respond', return_to: '/workspaces/service-queues' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('recalls a skipped ticket with an optional room', () => {
    render(<QueueBoard {...props} />);
    fireEvent.click(screen.getByRole('tab', { name: 'Needs Recall (2)' }));
    fireEvent.click(screen.getAllByRole('button', { name: 'Recall' })[0]!);
    expect(screen.getByRole('dialog', { name: 'Recall ticket OPD-004' })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Room (optional)'), {
      target: { value: 'room-1' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Recall ticket' }));
    expect(actionState.post).toHaveBeenCalledWith(
      '/workspaces/service-queues/entries/entry-skipped/recall',
      { room: 'room-1', return_to: '/workspaces/service-queues?tab=needs-recall' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('transfers a waiting ticket to another queue with an optional reason', () => {
    render(<QueueBoard {...props} />);
    fireEvent.click(screen.getAllByRole('button', { name: 'Additional actions' })[0]!);
    fireEvent.click(screen.getByRole('menuitem', { name: 'Transfer' }));
    const dialog = screen.getByRole('dialog', { name: 'Transfer ticket OPD-001' });
    expect(within(dialog).queryByRole('option', { name: /General OPD/ })).not.toBeInTheDocument();
    expect(within(dialog).getByRole('option', { name: 'Triage' })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Destination queue'), {
      target: { value: 'queue-b' },
    });
    fireEvent.change(screen.getByLabelText('Reason (optional)'), {
      target: { value: 'Referred to triage' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Transfer ticket' }));
    expect(actionState.post).toHaveBeenCalledWith(
      '/workspaces/service-queues/entries/entry-a/transfer',
      {
        destination_queue_id: 'queue-b',
        reason: 'Referred to triage',
        return_to: '/workspaces/service-queues',
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });
});
