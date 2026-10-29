import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { dayInZone } from '@/lib/formatting';
import { FrontDeskBoard } from './FrontDeskBoard';

const state = vi.hoisted(() => ({
  canCheckIn: true,
  canRead: true,
  canRegister: true,
  canRoute: false,
  searchPending: false,
  searchError: null as string | null,
  searchActiveEncounter: false,
  get: vi.fn(),
  visit: vi.fn(),
  postForm: vi.fn(),
  listeners: {} as Record<string, () => void>,
}));

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');
  return {
    Link: ({ children, href, ...props }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
      <a href={href} {...props}>{children}</a>
    ),
    router: {
      get: state.get,
      visit: state.visit,
      on: (type: string, callback: () => void) => {
        state.listeners[type] = callback;
        return () => {};
      },
    },
    usePage: () => ({ props: { auth: { can: {} }, flash: {} } }),
    useForm: (initial: Record<string, unknown>) => {
      const [data, setDataState] = React.useState(initial);
      const [defaults, setDefaultsState] = React.useState(initial);
      const setData = React.useCallback((key: string, value: unknown) => {
        setDataState((current) => ({ ...current, [key]: value }));
      }, []);
      const setDefaults = React.useCallback((key?: string, value?: unknown) => {
        if (key === undefined) {
          setDefaultsState(data);
        } else {
          setDefaultsState((current) => ({ ...current, [key]: value }));
        }
      }, [data]);
      return {
        data,
        setData,
        setDefaults,
        reset: () => setDataState(defaults),
        post: state.postForm,
        processing: false,
        errors: {},
        isDirty: JSON.stringify(data) !== JSON.stringify(defaults),
      };
    },
  };
});

vi.mock('@/lib/http', () => ({
  getJson: vi.fn(() => Promise.resolve({
    facilityId: 'facility-1',
    facilityName: 'Demo General Hospital',
    departments: [{
      id: 'department-1',
      name: 'Outpatient',
      code: 'OPD',
      units: [{ id: 'unit-1', name: 'Triage', code: 'TRIAGE' }],
    }, {
      id: 'department-2',
      name: 'Emergency',
      code: 'ER',
      units: [{ id: 'unit-2', name: 'Emergency Triage', code: 'ER-TRIAGE' }],
    }],
  })),
}));

vi.mock('@/lib/authorization', () => ({
  permissions: {
    patient: { read: 'patient.patients.read', create: 'patient.patients.create' },
    encounter: { create: 'encounter.encounters.create' },
    queue: { ticketPlace: 'queue.ticket.place' },
    organization: { structureRead: 'organization.structure.read' },
  },
  useCan: () => (permission: string) =>
    (permission === 'encounter.encounters.create' && state.canCheckIn)
    || (permission === 'queue.ticket.place' && state.canCheckIn)
    || (permission === 'patient.patients.read' && state.canRead)
    || (permission === 'patient.patients.create' && state.canRegister)
    || (permission === 'organization.structure.read' && state.canRoute),
}));

vi.mock('@/components/patient-search/usePatientSearch', () => ({
  usePatientSearch: (term: string) => ({
    rows: term.trim() && !term.includes('zzz')
      ? [{
        patientId: 'patient-9',
        patientNumber: 'PAT-009',
        displayName: 'Walk In',
        sex: 'F',
        age: null,
        formattedDob: null,
        photoUrl: '/photos/walk-in.jpg',
        activeEncounter: state.searchActiveEncounter ? { encounterNumber: 'ENC-0009' } : null,
      }]
      : [],
    loading: false,
    isPending: state.searchPending,
    errorMessage: state.searchError,
  }),
}));

function baseProps() {
  return {
    date: '2026-10-03',
    timezone: 'Africa/Dar_es_Salaam',
    encounters: [{
      encounterId: 'enc-1',
      encounterNumber: 'ENC-0001',
      patientId: 'patient-1',
      facilityId: 'facility-1',
      encounterType: 'OUTPATIENT',
      status: 'ARRIVED',
      disposition: null,
      departmentId: null,
      unitId: null,
      arrivedAt: '2026-10-03T08:15:00+03:00',
      startedAt: null,
      admittedAt: null,
      completedAt: null,
      createdSource: 'RECEPTION',
      queueStatus: null,
      queueName: null,
      ticketNumber: null,
      vitalsRecorded: null,
      triageRecorded: null,
      patient: {
        patientId: 'patient-1', patientNumber: 'PAT-001', displayName: 'Jane Doe',
        dateOfBirth: null, sex: 'F', status: 'ACTIVE', isProvisional: false,
      },
    }],
    appointments: [{
      id: 'appt-1',
      facilityId: 'facility-1',
      appointmentNumber: 'APT-001',
      slotId: null,
      patientId: 'patient-2',
      resourceId: 'res-1',
      appointmentTypeId: 'type-1',
      scheduledStart: '2026-10-03T09:00:00+03:00',
      scheduledEnd: '2026-10-03T09:30:00+03:00',
      status: 'BOOKED',
      reason: null,
      patient: {
        patientId: 'patient-2', patientNumber: 'PAT-002', displayName: 'John Smith',
        dateOfBirth: null, sex: 'M', status: 'ACTIVE', isProvisional: false,
      },
      appointmentTypeName: 'General Consultation',
      resourceName: 'Dr. Amina',
    }],
    metrics: {
      totalEncounters: 1, waitingEncounters: 1, inServiceEncounters: 0,
      inServicePatients: 0, activeAttendanceEncounters: 1, completedEncounters: 0, totalAppointments: 1,
      expectedAppointments: 1, arrivedAppointments: 0, inServiceAppointments: 0,
      completedAppointments: 0,
    },
    availableQueues: [{
      id: 'q-1',
      departmentId: 'department-1',
      name: 'General OPD',
      code: 'OPD',
      serviceCapability: 'CONSULTATION',
      roomNames: ['Consultation Room 1'],
    }, {
      id: 'q-2',
      departmentId: 'department-2',
      name: 'Emergency',
      code: 'ER',
      serviceCapability: 'TRIAGE',
      roomNames: ['Emergency Triage'],
    }],
    preselectedPatient: null,
    activeEncounter: null,
    rosterStatus: 'ALL',
    rosterFilter: '',
    weekLabel: 'Week of October 4 - 10, 2026',
    days: [
      { dayOfWeek: 'SUN', date: '2026-10-04', label: 'SUN 10/4', isToday: false, isSelected: false },
      { dayOfWeek: 'MON', date: '2026-10-05', label: 'MON 10/5', isToday: true, isSelected: false },
    ],
    restricted: [],
  };
}

beforeEach(() => {
  state.canCheckIn = true;
  state.canRead = true;
  state.canRegister = true;
  state.canRoute = false;
  state.searchPending = false;
  state.searchError = null;
  state.searchActiveEncounter = false;
  vi.clearAllMocks();
  globalThis.ResizeObserver ??= class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
});

describe('FrontDeskBoard Today view', () => {
  it('renders summary-only metric cards and the merged time-ordered roster', () => {
    const { container } = render(<FrontDeskBoard {...baseProps()} />);

    expect(screen.getByRole('region', { name: 'Front Desk summary' })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Front Desk summary' }).querySelectorAll('a')).toHaveLength(0);
    expect(screen.getByRole('button', { name: 'Filter roster by status' })).toBeInTheDocument();
    expect(container.querySelector('.front-desk-roster__summary')?.textContent).toMatch(/2 roster entries/);
    expect(container.querySelector('.front-desk-roster__scroll')).toBeNull();
    expect(screen.getByText('roster entries · 1 expected appointment')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Jane Doe' })).toHaveAttribute(
      'href', '/patient/patient-1/chart',
    );
    expect(screen.getByText(/APT-001 · General Consultation · Dr. Amina/)).toBeInTheDocument();

    const times = Array.from(container.querySelectorAll('.front-desk-roster__table tbody time'))
      .map((time) => time.getAttribute('dateTime'));
    expect(times).toEqual(['2026-10-03T08:15:00+03:00', '2026-10-03T09:00:00+03:00']);
  });

  it('opens a visit summary popover from the roster encounter number', () => {
    render(<FrontDeskBoard {...baseProps()} />);

    expect(screen.queryByText('Visit number')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Visit summary for ENC-0001' }));

    const summary = document.querySelector('.front-desk-visit-summary__panel');
    expect(summary).not.toBeNull();
    expect(within(summary as HTMLElement).getByText('Visit number')).toBeInTheDocument();
    expect(within(summary as HTMLElement).getByText('ENC-0001')).toBeInTheDocument();
    expect(within(summary as HTMLElement).getByText('Outpatient')).toBeInTheDocument();
    expect(within(summary as HTMLElement).getByText('03-Oct-2026, 08:15 GMT+3')).toBeInTheDocument();
  });

  it('shows the live queue status and permitted clinical documentation indicators', () => {
    const props = baseProps();
    const encounter = props.encounters[0];
    if (!encounter) throw new Error('Expected a test encounter');

    render(<FrontDeskBoard
      {...props}
      encounters={[{
        ...encounter,
        queueStatus: 'IN_SERVICE',
        queueName: 'OPD Triage',
        ticketNumber: 'T-001',
        vitalsRecorded: true,
        triageRecorded: true,
      }]}
    />);

    expect(screen.getByText('OPD Triage · In service · #T-001')).toBeInTheDocument();
    expect(screen.getByText('Vitals recorded')).toBeInTheDocument();
    expect(screen.getByText('Triage recorded')).toBeInTheDocument();
  });

  it('renders roster times in UTC so cells agree with the selected day', () => {
    const { container } = render(<FrontDeskBoard {...baseProps()} />);

    // Fixture instants carry +03:00 offsets; the facility zone owns them.
    const times = Array.from(container.querySelectorAll('.front-desk-roster__table tbody time'))
      .map((time) => time.textContent);
    expect(times).toEqual(['08:15 GMT+3', '09:00 GMT+3']);
  });

  it('labels the roster with Today when the selected date is today', () => {
    const today = dayInZone('Africa/Dar_es_Salaam');
    const now = new Date().toISOString();
    const base = baseProps();
    const firstEncounter = base.encounters[0];
    if (!firstEncounter) throw new Error('roster fixture is missing its encounter');
    render(
      <FrontDeskBoard
        {...base}
        date={today}
        encounters={[{ ...firstEncounter, arrivedAt: now }]}
      />,
    );

    expect(screen.getByText("Today's roster")).toBeInTheDocument();
    expect(screen.getByRole('table', { name: /Today's roster/ })).toBeInTheDocument();
    expect(screen.getByText('Status: All statuses')).toBeInTheDocument();
  });

  it('offers check-in on checkable appointment rows when permitted', () => {
    render(<FrontDeskBoard {...baseProps()} />);

    const table = screen.getByRole('table', { name: /Roster for 03-Oct-2026/ });
    expect(within(table).getByRole('columnheader', { name: 'Actions' })).toBeInTheDocument();
    const checkIn = screen.getByRole('button', { name: 'Check in' });
    expect(checkIn).toHaveClass('cds--btn--primary');
    fireEvent.click(checkIn);
    expect(screen.getByRole('dialog', { name: 'Check in patient' })).toBeInTheDocument();
    const patient = within(screen.getByRole('dialog', { name: 'Check in patient' }))
      .getByRole('group', { name: 'Selected patient' });
    expect(within(patient).getByText('John Smith')).toBeInTheDocument();
    expect(within(patient).getByText('PAT-002')).toBeInTheDocument();
    expect(screen.getByText(/Appointment APT-001/)).toBeInTheDocument();
  });

  it('hides check-in without the encounter create permission', () => {
    state.canCheckIn = false;
    render(<FrontDeskBoard {...baseProps()} />);

    expect(screen.queryByRole('button', { name: 'Check in' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'John Smith' })).toBeInTheDocument();
  });

  it('shows restricted notice and falls back without patient details', () => {
    const props = baseProps();
    render(
      <FrontDeskBoard
        {...props}
        restricted={['patients']}
        encounters={props.encounters.map((encounter) => ({ ...encounter, patient: null }))}
      />,
    );

    expect(screen.getByText(/restricted for your role/)).toBeInTheDocument();
    expect(screen.getByText('Patient details restricted')).toBeInTheDocument();
  });

  it('shows the empty state and week navigation for a quiet day', () => {
    render(<FrontDeskBoard {...baseProps()} encounters={[]} appointments={[]} />);

    expect(screen.getByText('No patients expected for 03-Oct-2026')).toBeInTheDocument();
    const monday = screen.getByRole('link', { name: 'MON 10/5, today' });
    expect(monday).toHaveAttribute('href', '/workspaces/reception?date=2026-10-05');
  });

  it('offers a Today shortcut for another selected date and preserves roster filters', () => {
    render(
      <FrontDeskBoard
        {...baseProps()}
        date="2026-10-04"
        rosterStatus="BOOKED,ARRIVED"
        rosterFilter="Jane"
        days={baseProps().days.map((day) => ({
          ...day,
          isSelected: day.date === '2026-10-04',
        }))}
      />,
    );

    expect(screen.getByRole('link', { name: "Show today's roster" })).toHaveAttribute(
      'href',
      '/workspaces/reception?date=2026-10-05&status=BOOKED%2CARRIVED&filter=Jane',
    );
  });

  it('hides the Today shortcut when today is already selected', () => {
    render(
      <FrontDeskBoard
        {...baseProps()}
        date="2026-10-05"
        days={baseProps().days.map((day) => ({
          ...day,
          isSelected: day.isToday,
        }))}
      />,
    );

    expect(screen.queryByRole('link', { name: "Show today's roster" })).not.toBeInTheDocument();
  });

  it('offers registration when the roster search finds no one', () => {
    render(<FrontDeskBoard {...baseProps()} encounters={[]} appointments={[]} rosterFilter="Zzz" />);

    expect(screen.getByText('No roster entries match these filters')).toBeInTheDocument();
    expect(screen.getByText('Clear one or more filters to see more roster entries.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Register new patient' }));
    expect(state.visit).toHaveBeenCalledWith('/workspaces/patient/register?return_to_reception=1&date=2026-10-03&status=ALL&filter=Zzz');
  });

  it('hides registration without the patient create permission', () => {
    state.canRegister = false;
    render(<FrontDeskBoard {...baseProps()} encounters={[]} appointments={[]} rosterFilter="Zzz" />);

    expect(screen.queryByRole('button', { name: 'Register new patient' })).not.toBeInTheDocument();
  });

  it('marks the roster busy while it refreshes without shifting layout', () => {
    render(<FrontDeskBoard {...baseProps()} />);
    expect(screen.queryByText('Updating roster')).not.toBeInTheDocument();
    // Table is labelled by the container title, which now carries the
    // count — match on the leading title text.
    expect(screen.getByRole('table', { name: /Roster for 03-Oct-2026/ })).not.toHaveAttribute('aria-busy');

    act(() => { state.listeners.start?.(); });
    // Rule 07: refresh keeps stale rows + global progress bar, no per-page
    // spinner in the count line (it pushed the table). Table signals busy.
    expect(screen.queryByText('Updating roster')).not.toBeInTheDocument();
    expect(screen.getByRole('table', { name: /Roster for 03-Oct-2026/ })).toHaveAttribute('aria-busy', 'true');

    act(() => { state.listeners.finish?.(); });
    expect(screen.getByRole('table', { name: /Roster for 03-Oct-2026/ })).not.toHaveAttribute('aria-busy');
  });

  it('searches the roster through the verified filter param', async () => {
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search roster for 03-Oct-2026' }), {
      target: { value: 'Jane' },
    });

    // Live debounced server search — no Enter needed.
    await waitFor(() => {
      expect(state.get).toHaveBeenCalledWith(
        '/workspaces/reception',
        { date: '2026-10-03', status: 'ALL', filter: 'Jane' },
        expect.objectContaining({ preserveScroll: true }),
      );
    });
  });

  it('clears the roster search back to the full list', async () => {
    render(<FrontDeskBoard {...baseProps()} rosterFilter="Jane" />);

    const box = screen.getByRole('searchbox', { name: 'Search roster for 03-Oct-2026' });
    fireEvent.change(box, { target: { value: '' } });

    await waitFor(() => {
      expect(state.get).toHaveBeenCalledWith(
        '/workspaces/reception',
        { date: '2026-10-03', status: 'ALL', filter: '' },
        expect.objectContaining({ preserveScroll: true }),
      );
    });
  });

  it('filters encounter status without dropping the roster search', () => {
    render(<FrontDeskBoard {...baseProps()} rosterFilter="Jane" />);

    fireEvent.click(screen.getByRole('button', { name: 'Filter roster by status' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Arrived' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'In service' }));
    fireEvent.click(screen.getByRole('button', { name: 'Apply filter' }));

    expect(state.get).toHaveBeenCalledWith(
      '/workspaces/reception',
      { date: '2026-10-03', status: 'ARRIVED,ACTIVE', filter: 'Jane' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('filters the roster to booked appointments', () => {
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Filter roster by status' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Booked' }));
    fireEvent.click(screen.getByRole('button', { name: 'Apply filter' }));

    expect(state.get).toHaveBeenCalledWith(
      '/workspaces/reception',
      { date: '2026-10-03', status: 'BOOKED', filter: '' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('clears the status and search filters together', () => {
    render(<FrontDeskBoard {...baseProps()} rosterStatus="ARRIVED" rosterFilter="Jane" />);

    fireEvent.click(screen.getByRole('button', { name: 'Filter roster by status' }));
    fireEvent.click(screen.getByRole('button', { name: 'Reset filters' }));

    expect(state.get).toHaveBeenCalledWith(
      '/workspaces/reception',
      { date: '2026-10-03', status: 'ALL', filter: '' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('shows labelled arrival actions in the page header', () => {
    render(<FrontDeskBoard {...baseProps()} />);

    const header = screen.getByRole('heading', { name: 'Front Desk' }).parentElement?.parentElement;
    expect(header).not.toBeNull();
    expect(within(header as HTMLElement).getByRole('button', { name: 'Check in walk-in patient' })).toBeInTheDocument();
    expect(within(header as HTMLElement).queryByRole('button', { name: 'Register patient' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Check in walk-in patient' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'More tasks coming soon' })).not.toBeInTheDocument();
  });

  it('checks in a walk-in patient inside the same workspace', () => {
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    expect(screen.getByRole('dialog', { name: 'Check in walk-in patient' })).toBeInTheDocument();

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'Walk' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Walk In/ }));

    // Same workspace, form stage: footer mirrors the patient-profile flow.
    const workspace = screen.getByRole('dialog', { name: 'Check in walk-in patient' });
    expect(workspace).toBeInTheDocument();
    expect(screen.queryByRole('searchbox', { name: 'Search patients' })).not.toBeInTheDocument();
    expect(within(workspace).getByRole('group', { name: 'Selected patient' })).toBeInTheDocument();
    expect(workspace.querySelector('img')).toHaveAttribute('src', '/photos/walk-in.jpg');
    expect(within(workspace).getByRole('combobox', { name: 'Encounter type' })).toBeInTheDocument();
    expect(within(workspace).queryByRole('combobox', { name: 'Department' })).not.toBeInTheDocument();
    expect(within(workspace).getByRole('button', { name: 'Check in' })).toBeDisabled();
    fireEvent.change(within(workspace).getByLabelText('Encounter type'), { target: { value: 'OUTPATIENT' } });
    expect(within(workspace).getByRole('button', { name: 'Check in' })).toBeEnabled();

    fireEvent.click(within(workspace).getByRole('button', { name: 'Cancel' }));
    fireEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
    expect(screen.queryByRole('dialog', { name: 'Check in walk-in patient' })).not.toBeInTheDocument();
    expect(screen.queryByRole('searchbox', { name: 'Search patients' })).not.toBeInTheDocument();
  });

  it('offers registration when the walk-in search finds no one', async () => {
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'zzz unknown' },
    });

    expect(screen.getByText(/No matching patient found/)).toBeInTheDocument();
    const register = screen.getByRole('button', { name: 'Register new patient' });
    // Footer action: full-width, flush at the panel bottom.
    expect(register.closest('.afy-dock__actions')).not.toBeNull();
    const cancel = within(screen.getByRole('dialog', { name: 'Check in walk-in patient' }))
      .getByRole('button', { name: 'Cancel' });
    expect(cancel.closest('.afy-dock__actions')).toBe(register.closest('.afy-dock__actions'));
    fireEvent.click(cancel);
    expect(screen.queryByRole('dialog', { name: 'Check in walk-in patient' })).not.toBeInTheDocument();
    expect(state.visit).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'zzz unknown' },
    });
    const registerAgain = await screen.findByRole('button', { name: 'Register new patient' });
    fireEvent.click(registerAgain);
    expect(state.visit).toHaveBeenCalledWith('/workspaces/patient/register?return_to_reception=1&date=2026-10-03&status=ALL');
  });

  it('waits for 3 characters before searching walk-in patients', () => {
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'Wa' },
    });

    expect(screen.getByText('Type at least 3 characters to search.')).toBeInTheDocument();
    expect(screen.queryByText(/No matching patient found/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Register new patient' })).not.toBeInTheDocument();
  });

  it('hides walk-in registration without the patient create permission', () => {
    state.canRegister = false;
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'zzz unknown' },
    });

    expect(screen.getByText(/No matching patient found/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Register new patient' })).not.toBeInTheDocument();
  });

  it('does not offer registration until the current search has settled', () => {
    state.searchPending = true;
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'zzz unknown' },
    });

    expect(screen.getByText('Searching patients')).toBeInTheDocument();
    expect(screen.queryByText(/No matching patient found/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Register new patient' })).not.toBeInTheDocument();
  });

  it('does not offer registration when search fails', () => {
    state.searchError = 'Could not reach the server. Check your connection and try again.';
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'zzz unknown' },
    });

    expect(screen.getByRole('alert')).toHaveTextContent('Could not reach the server.');
    expect(screen.queryByText(/No matching patient found/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Register new patient' })).not.toBeInTheDocument();
  });

  it('blocks check-in for a search result that already has an active visit', () => {
    state.searchActiveEncounter = true;
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'Walk' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Walk In/ }));

    expect(screen.getByText('This patient already has an active visit')).toBeInTheDocument();
    expect(screen.getByText(/ENC-0009/)).toBeInTheDocument();
    expect(within(screen.getByRole('dialog', { name: 'Check in walk-in patient' }))
      .getByRole('button', { name: 'Check in' })).toBeDisabled();
  });

  it('shows a confirmation after check-in and lets the user start another patient', () => {
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'Walk' },
    });

    fireEvent.click(screen.getByRole('button', { name: /Walk In/ }));

    const workspace = screen.getByRole('dialog', { name: 'Check in walk-in patient' });
    fireEvent.change(within(workspace).getByLabelText('Encounter type'), { target: { value: 'OUTPATIENT' } });
    fireEvent.click(within(workspace).getByRole('button', { name: 'Check in' }));
    expect(state.postForm).toHaveBeenCalledWith(
      '/workspaces/reception/check-in',
      expect.objectContaining({ preserveScroll: true }),
    );

    act(() => {
      const postCall = state.postForm.mock.calls[0] as unknown as [string, { onSuccess: () => void }] | undefined;
      if (!postCall) throw new Error('Walk-in check-in was not submitted.');
      postCall[1].onSuccess();
    });
    expect(screen.getByRole('dialog', { name: 'Check in walk-in patient' })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Check-in confirmation' })).toHaveTextContent(
      'Walk In (PAT-009) is now on the Front Desk roster.',
    );
    expect(screen.getByRole('button', { name: 'Done' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Check in another patient' }));
    expect(screen.getByRole('searchbox', { name: 'Search patients' })).toBeInTheDocument();
    expect(screen.getByRole('searchbox', { name: 'Search patients' })).toHaveValue('');
  });

  it('shows routing choices progressively and filters queues by department', async () => {
    state.canRoute = true;
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'Walk' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Walk In/ }));

    const workspace = screen.getByRole('dialog', { name: 'Check in walk-in patient' });
    expect(within(workspace).queryByRole('combobox', { name: 'Department' })).not.toBeInTheDocument();
    fireEvent.change(within(workspace).getByLabelText('Encounter type'), { target: { value: 'OUTPATIENT' } });
    const department = await within(workspace).findByLabelText('Department');
    fireEvent.change(department, { target: { value: 'department-1' } });
    expect(await within(workspace).findByLabelText('Unit (optional)')).toBeInTheDocument();
    const queue = await within(workspace).findByLabelText('Place into queue (optional)');
    const options = Array.from((queue as HTMLSelectElement).options).map((option) => option.text);
    expect(options).toContain('General OPD (OPD) · CONSULTATION');
    expect(options).not.toContain('Emergency (ER) · TRIAGE');
    fireEvent.change(within(workspace).getByLabelText('Place into queue (optional)'), {
      target: { value: 'q-1' },
    });

    const destination = within(workspace).getByText('Called only to: Consultation Room 1.');
    expect(destination).toBeInTheDocument();
    expect(destination.closest('.cds--inline-notification')).toHaveClass('front-desk-queue-destination');

    fireEvent.change(department, { target: { value: 'department-2' } });
    expect((queue as HTMLSelectElement).value).toBe('');
    await waitFor(() => {
      const refreshedOptions = Array.from((queue as HTMLSelectElement).options).map((option) => option.text);
      expect(refreshedOptions).toContain('Emergency (ER) · TRIAGE');
      expect(refreshedOptions).not.toContain('General OPD (OPD) · CONSULTATION');
    });
  });

  it('hides walk-in check-in without patient read permission', () => {
    state.canRead = false;
    render(<FrontDeskBoard {...baseProps()} />);

    expect(screen.queryByRole('button', { name: 'Check in walk-in patient' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Register patient' })).toBeInTheDocument();
  });

  it('guards a dirty walk-in workspace before opening roster check-in', () => {
    render(<FrontDeskBoard {...baseProps()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'Walk' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Walk In/ }));
    fireEvent.change(screen.getByLabelText('Encounter type'), {
      target: { value: 'EMERGENCY' },
    });

    // Roster row check-in must pause at the discard prompt.
    const rosterTable = screen.getByRole('table', { name: /Roster for 03-Oct-2026/ });
    fireEvent.click(within(rosterTable).getByRole('button', { name: 'Check in' }));
    expect(screen.getByText('Discard unsaved changes?')).toBeInTheDocument();
    expect(screen.getByRole('dialog', { name: 'Check in walk-in patient' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
    expect(screen.queryByText('Discard unsaved changes?')).not.toBeInTheDocument();
    expect(screen.getByRole('dialog', { name: 'Check in walk-in patient' })).toBeInTheDocument();

    fireEvent.click(within(rosterTable).getByRole('button', { name: 'Check in' }));
    fireEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
    expect(screen.queryByText('Discard unsaved changes?')).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog', { name: 'Check in walk-in patient' })).not.toBeInTheDocument();
    expect(screen.getByRole('dialog', { name: 'Check in patient' })).toBeInTheDocument();
  });

  it('opens check-in ready for a preselected registration return', () => {
    render(<FrontDeskBoard
      {...baseProps()}
      preselectedPatient={{
        patientId: 'patient-9',
        patientNumber: 'PAT-009',
        displayName: 'Newborn Baby',
        sex: 'F',
        status: 'ACTIVE',
        isProvisional: false,
        hasAccessibleFacilityRegistration: true,
        survivingPatientId: null,
      }}
    />);

    // No search needed: the new patient is already selected in the workspace.
    const workspace = screen.getByRole('dialog', { name: 'Check in walk-in patient' });
    expect(screen.queryByRole('searchbox', { name: 'Search patients' })).not.toBeInTheDocument();
    const patient = within(workspace).getByRole('group', { name: 'Selected patient' });
    expect(within(patient).getByText('Newborn Baby')).toBeInTheDocument();
    expect(within(patient).getByText('PAT-009')).toBeInTheDocument();
    expect(within(workspace).getByRole('combobox', { name: 'Encounter type' })).toBeInTheDocument();
  });

  it('warns instead of opening check-in when the preselected patient is already visiting', () => {
    const props = baseProps();
    const encounter = props.encounters[0];
    if (!encounter) throw new Error('Board fixture has no encounter.');
    render(<FrontDeskBoard
      {...props}
      preselectedPatient={{
        patientId: 'patient-1',
        patientNumber: 'PAT-001',
        displayName: 'Jane Doe',
        sex: 'F',
        status: 'ACTIVE',
        isProvisional: false,
        hasAccessibleFacilityRegistration: true,
        survivingPatientId: null,
      }}
      activeEncounter={encounter}
    />);

    expect(screen.queryByRole('dialog', { name: 'Check in walk-in patient' })).not.toBeInTheDocument();
    expect(screen.getByText(/already has an active encounter/)).toBeInTheDocument();
  });

  it('carries the board context on registration entry links', () => {
    const expected = '/workspaces/patient/register?return_to_reception=1&date=2026-10-03&status=ARRIVED&filter=Jane';
    render(<FrontDeskBoard
      {...baseProps()}
      encounters={[]}
      appointments={[]}
      rosterStatus="ARRIVED"
      rosterFilter="Jane"
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Register new patient' }));
    expect(state.visit).toHaveBeenCalledWith(expected);
  });

  it('carries the board context on the walk-in registration action', () => {
    const expected = '/workspaces/patient/register?return_to_reception=1&date=2026-10-03&status=ARRIVED&filter=Jane';
    render(<FrontDeskBoard
      {...baseProps()}
      rosterStatus="ARRIVED"
      rosterFilter="Jane"
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in walk-in patient' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search patients' }), {
      target: { value: 'zzz unknown' },
    });
    const workspace = screen.getByRole('dialog', { name: 'Check in walk-in patient' });
    fireEvent.click(within(workspace).getByRole('button', { name: 'Register new patient' }));
    expect(state.visit).toHaveBeenCalledWith(expected);
  });
});
