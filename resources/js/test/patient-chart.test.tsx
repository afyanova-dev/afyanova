import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PatientChartProps } from '@/features/patients/types/chart';
import { PatientChart } from '@/features/patients/components/PatientChart';
import PatientProfile from '@/pages/Patients/Profile';

vi.mock('@carbon/react', async (importOriginal) => {
  const carbon = await importOriginal<typeof import('@carbon/react')>();
  const { Card } = await vi.importActual<typeof import('@carbon/react/lib/components/Card/Card')>(
    '@carbon/react/lib/components/Card/Card',
  );
  const {
    TestCarbonComboButton,
    TestCarbonMenuButton,
    TestCarbonMenuItem,
    TestCarbonPopover,
    TestCarbonPopoverContent,
  } = await import('@/test/carbon-floating-stubs');

  return {
    ...carbon,
    Card,
    ComboButton: TestCarbonComboButton,
    MenuButton: TestCarbonMenuButton,
    MenuItem: TestCarbonMenuItem,
    Popover: TestCarbonPopover,
    PopoverContent: TestCarbonPopoverContent,
  };
});

const state = vi.hoisted(() => ({
  url: '/patient/patient-1/chart',
  canRead: true,
  canBook: false,
  canManage: false,
  canUpdate: true,
  canMarkDeceased: false,
  canCreate: true,
  canRecordVitals: false,
  canOpenVitalsWorkflow: false,
  canReadConsultations: false,
  canPlaceQueue: false,
  getJson: vi.fn(),
  sendJson: vi.fn(),
  inertiaPost: vi.fn(),
  routerVisit: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');
  return {
    Link: ({ children, href, ...props }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
      <a href={href} {...props}>{children}</a>
    ),
    router: { visit: state.routerVisit },
    usePage: () => ({
      url: state.url,
      props: {
        auth: {
          can: {},
          user: null,
          roles: [],
          facilities: [],
          activeFacility: 'facility-1',
          contextMode: 'facility',
          canUseGeneral: false,
        },
        flash: {},
      },
    }),
    // Stable setData like real Inertia: the booking panel's availability
    // effect depends on it — a per-render identity would retrigger the
    // effect (and its setSlots) into an infinite render loop.
    // Stable setData like real Inertia: effects that depend on it must not
    // retrigger every render (see booking panel availability effect).
    useForm: (initial: Record<string, unknown>) => {
      const [data, setDataState] = React.useState(initial);
      const setData = React.useCallback((key: string, value: unknown) => {
        setDataState((current) => ({ ...current, [key]: value }));
      }, []);
      return {
        data,
        setData,
        setDefaults: vi.fn(),
        post: state.inertiaPost,
        patch: () => {},
        processing: false,
        errors: {},
        reset: () => {},
        transform: () => {},
      };
    },
  };
});

vi.mock('@/lib/http', () => ({
  getJson: state.getJson,
  sendJson: state.sendJson,
}));

vi.mock('@/lib/authorization', () => ({
  permissions: {
    patient: {
      read: 'patient.patients.read',
      update: 'patient.patients.update',
      markDeceased: 'patient.patients.mark_deceased',
    },
    scheduling: { book: 'sch.appointment.book', manage: 'sch.appointment.manage', recordArrival: 'sch.appointment.record_arrival' },
    encounter: {
      read: 'encounter.encounters.read',
      create: 'encounter.encounters.create',
      update: 'encounter.encounters.update',
    },
    queue: {
      ticketTransfer: 'queue.ticket.transfer',
      ticketView: 'queue.ticket.view',
      ticketPlace: 'queue.ticket.place',
    },
    clinical: {
      access: 'clinical.access',
      vitalsRead: 'clinical.vitals.read',
      vitalsRecord: 'clinical.vitals.record',
      triageRead: 'clinical.triage.read',
      consultationsRead: 'clinical.consultations.read',
    },
    organization: { structureRead: 'organization.structure.read' },
  },
  useCan: () => (permission: string) => {
    if (permission === 'patient.patients.read') return state.canRead;
    if (permission === 'sch.appointment.book') return state.canBook;
    if (permission === 'sch.appointment.manage') return state.canManage;
    if (permission === 'patient.patients.update') return state.canUpdate;
    if (permission === 'patient.patients.mark_deceased') return state.canMarkDeceased;
    if (permission === 'encounter.encounters.create') return state.canCreate;
    if (permission === 'clinical.vitals.record') return state.canRecordVitals;
    if (permission === 'queue.ticket.place') return state.canPlaceQueue;
    if (permission === 'clinical.access'
      || permission === 'clinical.vitals.read'
      || permission === 'clinical.triage.read'
      || permission === 'queue.ticket.view'
      || permission === 'encounter.encounters.read') {
      return state.canOpenVitalsWorkflow;
    }
    if (permission === 'clinical.consultations.read') return state.canReadConsultations;
    if (permission === 'organization.structure.read') return true;
    return false;
  },
}));

vi.mock('@/layouts/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  Breadcrumbs: ({ items }: { items: Array<{ label: string }> }) => (
    <nav aria-label="Breadcrumb">
      {items.map((item) => <span key={item.label}>{item.label}</span>)}
    </nav>
  ),
}));

const props: PatientChartProps = {
  patient: {
    patientId: 'patient-1',
    patientNumber: 'PAT-00000001',
    displayName: 'Amina Juma',
    givenName: 'Amina',
    familyName: 'Juma',
    middleName: null,
    dateOfBirth: '1990-02-03',
    dobPrecision: 'KNOWN',
    sex: 'FEMALE',
    status: 'ACTIVE',
    isProvisional: false,
    primaryPhone: '+255700000000',
    email: 'amina@example.test',
    registrationSource: 'RECEPTION',
    registeredAt: '2026-09-01T10:00:00Z',
    hasAccessibleFacilityRegistration: true,
    facilityRegistrationCount: 1,
    survivingPatientId: null,
    identifiers: [],
    addresses: [],
    relationships: [],
    auditHistory: [],
  },
  activeEncounter: null,
  availableQueues: [],
  queueEntry: null,
  photoUrl: null,
  chart: {
    allergies: [{
      id: 'allergy-1',
      substance: 'Penicillin',
      severity: 'MODERATE',
      status: 'ACTIVE',
      verification: 'CONFIRMED',
      reaction: 'Rash',
      onset: '2025-01-01',
    }],
    conditions: [],
    procedures: [],
    plans: [],
    vitals: [],
    medications: [],
    labOrders: [],
    labResults: {},
    imagingOrders: [],
    appointments: [],
    visits: [],
    programs: [],
    referrals: [],
    billing: { account: null, outstanding: null, ledger: [] },
    unavailable: [],
    restricted: [],
  },
};

beforeEach(() => {
  state.url = '/patient/patient-1/chart';
  state.canRead = true;
  state.canBook = false;
  state.canManage = false;
  state.canUpdate = true;
  state.canMarkDeceased = false;
  state.canCreate = true;
  state.canRecordVitals = false;
  state.canOpenVitalsWorkflow = false;
  state.canReadConsultations = false;
  state.canPlaceQueue = false;
  state.inertiaPost.mockReset();
  state.routerVisit.mockReset();
  state.sendJson.mockReset();
  state.getJson.mockResolvedValue({ facilityId: 'facility-1', facilityName: 'Demo', departments: [] });
  state.sendJson.mockResolvedValue({ stickyNote: null });
  globalThis.ResizeObserver ??= class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
});

describe('Patient chart', () => {
  it('gates the page with the patient read permission', () => {
    state.canRead = false;
    render(<PatientProfile {...props} />);

    expect(screen.getByRole('alert')).toHaveTextContent('You do not have permission to view patient records.');
    expect(screen.queryByRole('navigation', { name: 'Patient chart sections' })).not.toBeInTheDocument();
  });

  it('renders the patient profile without a breadcrumb trail', () => {
    render(<PatientProfile {...props} />);

    const banner = screen.getByRole('banner', { name: 'Patient header' });

    expect(within(banner).getByRole('heading', { name: 'Amina Juma' })).toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: 'Breadcrumb' })).not.toBeInTheDocument();
  });

  it('renders the patient header and default Patient Summary selection', () => {
    render(<PatientChart {...props} />);

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    expect(within(banner).getByRole('heading', { name: 'Amina Juma' })).toBeInTheDocument();
    expect(screen.getByText('PAT-00000001')).toBeInTheDocument();
    expect(within(banner).getByText('Female')).toBeInTheDocument();
    expect(within(banner).getByText('DOB 03-Feb-1990')).toBeInTheDocument();
    expect(within(banner).getByText(/\d+ yrs/)).toBeInTheDocument();
    expect(screen.queryByText('Phone: +255700000000')).not.toBeInTheDocument();
    expect(screen.queryByText('Email: amina@example.test')).not.toBeInTheDocument();
    expect(within(banner).getByText('Female').querySelector('svg')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Patient Summary' })).toBeInTheDocument();
    expect(document.querySelector('.afy-summary-card-grid')).not.toBeNull();
    expect(screen.getByText('Conditions')).toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: 'Patient chart sections' })).not.toBeInTheDocument();
  });

  it('opens the permission-gated patient editor from Patient Summary', () => {
    render(<PatientChart {...props} />);

    const editButton = screen.getByRole('button', { name: 'Edit patient information' });
    expect(editButton.closest('.cds--card__header')).not.toBeNull();
    expect(editButton.closest('.cds--card__footer')).toBeNull();

    fireEvent.click(editButton);

    expect(screen.getByRole('dialog', { name: 'Edit demographics' })).toBeInTheDocument();
  });

  it('hides the Patient Summary edit action without patient update permission', () => {
    state.canUpdate = false;
    render(<PatientChart {...props} />);

    expect(screen.queryByRole('button', { name: 'Edit patient information' })).not.toBeInTheDocument();
  });

  it('offers vital recording only through an authorized active triage workflow', () => {
    state.canRecordVitals = true;
    state.canOpenVitalsWorkflow = true;
    render(
      <PatientChart
        {...props}
        activeEncounter={{
          encounterId: 'encounter-1',
          encounterNumber: 'ENC-1',
          encounterType: 'OUTPATIENT',
          status: 'ACTIVE',
          departmentId: null,
          unitId: null,
          arrivedAt: '2026-09-01T10:00:00Z',
          startedAt: '2026-09-01T10:20:00Z',
        }}
        queueEntry={{
          entryId: 'entry-1',
          queueId: 'queue-1',
          serviceCapability: 'TRIAGE',
          ticketNumber: 'T-001',
          priority: 'NORMAL',
          status: 'IN_SERVICE',
        }}
      />,
    );

    expect(screen.getByRole('link', { name: 'Record vital signs' })).toHaveAttribute(
      'href',
      '/workspaces/nurse-station/entries/entry-1',
    );
  });

  it('does not offer vital recording without required permissions, an active encounter, or a triage queue entry', () => {
    state.canOpenVitalsWorkflow = true;
    const activeEncounter: NonNullable<PatientChartProps['activeEncounter']> = {
      encounterId: 'encounter-1',
      encounterNumber: 'ENC-1',
      encounterType: 'OUTPATIENT',
      status: 'ACTIVE',
      departmentId: null,
      unitId: null,
      arrivedAt: '2026-09-01T10:00:00Z',
      startedAt: '2026-09-01T10:20:00Z',
    };
    const triageQueueEntry: NonNullable<PatientChartProps['queueEntry']> = {
      entryId: 'entry-1',
      queueId: 'queue-1',
      serviceCapability: 'TRIAGE',
      ticketNumber: 'T-001',
      priority: 'NORMAL',
      status: 'IN_SERVICE',
    };

    const { rerender } = render(
      <PatientChart
        {...props}
        activeEncounter={activeEncounter}
        queueEntry={triageQueueEntry}
      />,
    );

    expect(screen.queryByRole('link', { name: 'Record vital signs' })).not.toBeInTheDocument();

    state.canRecordVitals = true;
    rerender(<PatientChart {...props} />);

    expect(screen.queryByRole('link', { name: 'Record vital signs' })).not.toBeInTheDocument();

    rerender(
      <PatientChart
        {...props}
        activeEncounter={activeEncounter}
        queueEntry={{
          entryId: 'entry-1',
          queueId: 'queue-1',
          serviceCapability: 'CONSULTATION',
          ticketNumber: 'T-001',
          priority: 'NORMAL',
          status: 'IN_SERVICE',
        }}
      />,
    );

    expect(screen.queryByRole('link', { name: 'Record vital signs' })).not.toBeInTheDocument();

    rerender(
      <PatientChart
        {...props}
        activeEncounter={activeEncounter}
        queueEntry={{ ...triageQueueEntry, status: 'CALLED' }}
      />,
    );

    expect(screen.queryByRole('link', { name: 'Record vital signs' })).not.toBeInTheDocument();

    state.canOpenVitalsWorkflow = false;
    rerender(
      <PatientChart
        {...props}
        activeEncounter={activeEncounter}
        queueEntry={triageQueueEntry}
      />,
    );

    expect(screen.queryByRole('link', { name: 'Record vital signs' })).not.toBeInTheDocument();
  });

  it('shows active clinical context and safety information in the patient banner', () => {
    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          conditions: [{
            id: 'condition-1',
            description: 'Hypertension',
            code: 'I10',
            status: 'ACTIVE',
            onset: '2024-01-01',
            recordedAt: '2024-01-01T09:00:00Z',
          }],
          medications: [{
            id: 'medication-1',
            facilityId: 'facility-1',
            number: 'RX-1',
            medication: 'Amlodipine',
            dose: '5 mg',
            frequency: 'Once daily',
            route: 'Oral',
            status: 'ACTIVE',
            recordedAt: '2026-08-01T09:00:00Z',
          }],
          vitals: [{
            id: 'vital-1',
            temperature: 38.9,
            heartRate: 72,
            respiratoryRate: 18,
            systolicBp: 120,
            diastolicBp: 80,
            oxygenSaturation: 98,
            weight: 64,
            height: 165,
            painScore: 2,
            occurredAt: '2026-09-01T09:00:00Z',
          }],
        }}
      />,
    );

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    const safety = within(banner).getByRole('region', { name: 'Patient safety summary' });
    expect(safety).toHaveClass('patient-chart__banner-badges');
    expect(within(safety).getByText(/Penicillin/)).toBeInTheDocument();
    expect(within(safety).getByText(/Moderate/)).toBeInTheDocument();
    expect(document.querySelector('.patient-chart__safety')).toBeNull();

    const conditions = screen.getByLabelText('Conditions');
    const medications = screen.getByLabelText('Medications');
    const vitals = screen.getByLabelText('Vitals');

    expect(within(conditions).getByText('Hypertension')).toBeInTheDocument();
    expect(within(medications).getByText('Amlodipine')).toBeInTheDocument();
    expect(within(medications).getByText(/5 mg/)).toBeInTheDocument();
    expect(within(vitals).getByText('120/80')).toBeInTheDocument();
    expect(within(vitals).getByText('2/10')).toBeInTheDocument();
    expect(within(vitals).getByText('38.9°C')).toBeInTheDocument();
    expect(within(vitals).getByText('High')).toBeInTheDocument();
  });

  it('filters the longitudinal timeline by event type', () => {
    state.url = '/patient/patient-1/chart/timeline';
    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          billing: {
            account: null,
            outstanding: null,
            ledger: [{
              entryType: 'Payment',
              direction: 'CREDIT',
              amount: '50000',
              currency: 'TZS',
              postedAt: '2026-09-02T10:00:00Z',
            }],
          },
        }}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Patient timeline' })).toBeInTheDocument();
    expect(screen.getAllByText('1 event')).toHaveLength(3);
    expect(screen.queryByText('Longitudinal record')).not.toBeInTheDocument();
    expect(screen.queryByText(/Registration through ongoing care/)).not.toBeInTheDocument();
    expect(screen.queryByText('Restricted sections excluded')).not.toBeInTheDocument();
    expect(screen.getByText('Patient registered')).toBeInTheDocument();
    expect(screen.getByText('Allergy: Penicillin')).toBeInTheDocument();
    expect(screen.getByText('Payment')).toBeInTheDocument();
    expect(screen.getByText('01-Sep-2026')).toBeInTheDocument();
    const registration = screen.getByText('Patient registered').closest('li');
    expect(registration).toHaveClass('patient-chart__timeline-event--registration');
    expect(within(registration!).getByRole('time')).toHaveTextContent('10:00 UTC');
    expect(registration?.querySelector('.patient-chart__timeline-marker')).toBeInTheDocument();

    fireEvent.change(screen.getByRole('combobox', { name: 'Show' }), { target: { value: 'allergy' } });

    expect(screen.getByText('Allergy: Penicillin')).toBeInTheDocument();
    expect(screen.queryByText('Patient registered')).not.toBeInTheDocument();
    expect(screen.queryByText('Payment')).not.toBeInTheDocument();
  });

  it('explains restricted sections on the timeline', () => {
    state.url = '/patient/patient-1/chart/timeline';
    render(<PatientChart {...props} chart={{ ...props.chart, restricted: ['medications'] }} />);

    expect(screen.getByText('Restricted sections excluded')).toBeInTheDocument();
    expect(screen.getByText(/excluded from this timeline/)).toBeInTheDocument();
  });

  it('caps the overview activity list and defers the full ledger to the timeline', () => {
    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          appointments: Array.from({ length: 8 }, (_, index) => ({
            id: `appointment-${index}`,
            number: `APT-${index}`,
            resourceId: 'resource-1',
            appointmentTypeId: 'type-1',
            appointmentTypeName: 'Follow-up',
            resourceName: 'Clinic A',
            start: `2026-09-25T09:00:00Z`,
            end: `2026-09-25T09:30:00Z`,
            status: 'BOOKED',
            reason: null,
          })),
        }}
      />,
    );

    const activity = screen.getByText('Recent activity').closest<HTMLElement>('.afy-summary-card');
    expect(activity).not.toBeNull();
    expect(within(activity!).getAllByRole('listitem')).toHaveLength(5);
    expect(within(activity!).getByRole('link', { name: /View all/ })).toHaveAttribute(
      'href',
      '/patient/patient-1/chart/timeline',
    );
  });

  it('creates and displays a patient-level sticky note', async () => {
    state.sendJson
      .mockResolvedValueOnce({ stickyNote: null })
      .mockResolvedValueOnce({
        stickyNote: {
          id: 'sticky-note-1',
          body: 'Call family before discharge',
          author: 'Demo Receptionist',
          updatedBy: null,
          createdAt: '2026-10-04T10:00:00Z',
          updatedAt: '2026-10-04T10:00:00Z',
        },
      });
    render(<PatientChart {...props} />);

    const stickyNoteButton = await screen.findByRole('button', { name: 'Sticky note' });
    await waitFor(() => expect(stickyNoteButton.closest('[aria-busy]')).toHaveAttribute('aria-busy', 'false'));
    fireEvent.click(stickyNoteButton);

    const textarea = await screen.findByPlaceholderText('Enter your sticky note here...');
    fireEvent.change(textarea, { target: { value: 'Call family before discharge' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(state.sendJson).toHaveBeenLastCalledWith(
      '/patient/patient-1/sticky-note',
      'POST',
      { body: 'Call family before discharge' },
    ));

    expect(await screen.findByText('Call family before discharge')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Sticky note details' })).toBeInTheDocument();
    expect(screen.getByText(/Demo Receptionist/)).toBeInTheDocument();
    expect(document.querySelector('.cds--badge-indicator, .patient-sticky-note__badge')).toHaveTextContent('1');
  });

  it('shows additional patient details in the Patient Summary instead of the banner', () => {
    const patient: PatientChartProps['patient'] = {
      ...props.patient,
      identifiers: [{
        id: 'identifier-1',
        type: 'NATIONAL_ID',
        value: 'NIDA-123',
        isVerified: true,
      }],
      addresses: [{
        id: 'address-1',
        type: 'HOME',
        region: 'Dar es Salaam',
        district: 'Kinondoni',
        ward: 'Mikocheni',
        streetOrVillage: 'Mwai Kibaki Road',
        postalCode: null,
      }],
    };
    render(<PatientChart {...props} patient={patient} />);

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    const details = screen.getByRole('region', { name: 'Patient details' });

    expect(within(banner).queryByRole('button', { name: /Show (more|less)/ })).not.toBeInTheDocument();
    expect(within(details).getByText('Phone')).toBeInTheDocument();
    expect(within(details).getByText('+255700000000')).toBeInTheDocument();
    expect(within(details).getByText('Email')).toBeInTheDocument();
    expect(within(details).getByText('NIDA-123 · Verified')).toBeInTheDocument();
    expect(within(details).getByText('Mwai Kibaki Road, Mikocheni, Kinondoni, Dar es Salaam')).toBeInTheDocument();
  });

  it('uses a sticky note icon button with badge and icon-enabled patient action items in MenuButton', () => {
    state.canMarkDeceased = true;
    render(<PatientChart {...props} />);

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    const stickyNoteButton = within(banner).getByRole('button', { name: 'Sticky note' });
    expect(stickyNoteButton).toBeInTheDocument();

    fireEvent.click(within(banner).getByRole('button', { name: 'Actions' }));

    expect(screen.getByRole('menuitem', { name: 'Edit patient details' }).querySelector('svg')).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Mark patient deceased' }).querySelector('svg')).toBeInTheDocument();
  });

  it('keeps the sticky note icon button and Actions MenuButton in the banner toolbar without a details toggle', () => {
    render(<PatientChart {...props} />);

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    const toolbar = banner.querySelector<HTMLElement>('.patient-chart__banner-action-row');

    expect(toolbar).toBeInTheDocument();
    expect(within(toolbar!).getByRole('button', { name: 'Sticky note' })).toBeInTheDocument();
    expect(within(toolbar!).getByRole('button', { name: 'Actions' })).toBeInTheDocument();
    expect(within(toolbar!).queryByRole('button', { name: /Show (more|less)/ })).not.toBeInTheDocument();
  });

  it('shows Book appointment in the Appointments section when booking is allowed', () => {
    state.url = '/patient/patient-1/chart/appointments';
    state.canBook = true;
    render(<PatientChart {...props} />);

    expect(screen.getByRole('region', { name: 'Appointments' })).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Book appointment' })).toHaveLength(1);
  });

  it('hides Book appointment from the Appointments section without booking permission', () => {
    state.url = '/patient/patient-1/chart/appointments';
    render(<PatientChart {...props} />);

    expect(screen.getByRole('region', { name: 'Appointments' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Book appointment' })).not.toBeInTheDocument();
  });

  it('shows visit status and encounter type in the patient banner', () => {
    render(
      <PatientChart
        {...props}
        activeEncounter={{
          encounterId: 'encounter-1',
          encounterNumber: 'ENC-1',
          encounterType: 'OUTPATIENT',
          status: 'ARRIVED',
          departmentId: null,
          unitId: null,
          arrivedAt: '2026-09-01T10:00:00Z',
          startedAt: null,
        }}
      />,
    );

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    const nameRow = banner.querySelector('.patient-chart__name-row');
    expect(nameRow).toHaveTextContent('Active visit');
    const patientDetails = within(banner).getByLabelText('Patient details');
    expect(patientDetails).toHaveTextContent('DOB');
    expect(patientDetails).not.toHaveTextContent('Outpatient');
    expect(patientDetails).not.toHaveTextContent('ENC-1');
    expect(screen.queryByText('Active encounter ENC-1')).not.toBeInTheDocument();
  });

  it('keeps patient identity to two lines and adds concise queue context', () => {
    const activeEncounter: NonNullable<PatientChartProps['activeEncounter']> = {
      encounterId: 'encounter-1',
      encounterNumber: 'ENC-1',
      encounterType: 'OUTPATIENT',
      status: 'ACTIVE',
      departmentId: null,
      unitId: null,
      arrivedAt: '2026-09-01T10:00:00Z',
      startedAt: '2026-09-01T10:20:00Z',
    };
    const { rerender } = render(
      <PatientChart
        {...props}
        activeEncounter={activeEncounter}
        queueEntry={{
          entryId: 'entry-1',
          queueId: 'queue-1',
          serviceCapability: 'TRIAGE',
          ticketNumber: 'T-001',
          priority: 'EMERGENCY',
          status: 'IN_SERVICE',
        }}
      />,
    );

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    const patientDetails = within(banner).getByLabelText('Patient details');
    const nameRow = banner.querySelector('.patient-chart__name-row');
    const identityCopy = screen.getByRole('heading', { name: 'Amina Juma' }).parentElement?.parentElement;
    expect(identityCopy?.children).toHaveLength(2);
    expect(nameRow).toHaveTextContent('Amina Juma');
    expect(nameRow).toHaveTextContent('Active visit');
    expect(nameRow).not.toHaveTextContent('In Service');
    expect(nameRow).not.toHaveTextContent('Outpatient');
    expect(nameRow).not.toHaveTextContent('ENC-1');
    expect(nameRow).not.toHaveTextContent('Triage T-001');
    expect(nameRow).toHaveTextContent('Emergency');
    expect(patientDetails).toHaveTextContent('DOB 03-Feb-1990');
    expect(patientDetails).toHaveTextContent('PAT-00000001');
    expect(patientDetails).not.toHaveTextContent('Triage');

    fireEvent.click(screen.getByRole('button', { name: 'Visit summary for ENC-1' }));
    const visitSummary = document.querySelector('.patient-chart__visit-summary');
    expect(visitSummary).not.toBeNull();
    expect(within(visitSummary as HTMLElement).getByText('ENC-1')).toBeInTheDocument();
    expect(within(visitSummary as HTMLElement).getByText('Outpatient')).toBeInTheDocument();
    expect(within(visitSummary as HTMLElement).getByText('Triage · T-001')).toBeInTheDocument();
    expect(within(visitSummary as HTMLElement).getByText('In Service')).toBeInTheDocument();
    expect(within(visitSummary as HTMLElement).getByText('Emergency')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Visit summary for ENC-1' }));
    rerender(
      <PatientChart
        {...props}
        activeEncounter={activeEncounter}
        queueEntry={{
          entryId: 'entry-2',
          queueId: 'queue-2',
          serviceCapability: 'CONSULTATION',
          ticketNumber: 'C-001',
          priority: 'ROUTINE',
          status: 'IN_SERVICE',
        }}
      />,
    );

    expect(banner.querySelector('.patient-chart__name-row')).not.toHaveTextContent('Consultation');
    expect(banner.querySelector('.patient-chart__name-row')).toHaveTextContent('Active visit');
    expect(patientDetails).not.toHaveTextContent('Consultation');
    expect(within(banner).queryByText('Routine')).not.toBeInTheDocument();
  });

  it('opens a visit summary popover from the Active visit badge', () => {
    render(
      <PatientChart
        {...props}
        activeEncounter={{
          encounterId: 'encounter-1',
          encounterNumber: 'ENC-1',
          encounterType: 'OUTPATIENT',
          status: 'ARRIVED',
          departmentId: null,
          unitId: null,
          arrivedAt: '2026-09-01T10:00:00Z',
          startedAt: null,
        }}
      />,
    );

    expect(screen.queryByText('Visit number')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Visit summary for ENC-1' }));

    const summary = document.querySelector('.patient-chart__visit-summary');
    expect(summary).not.toBeNull();
    expect(within(summary as HTMLElement).getByText('Visit number')).toBeInTheDocument();
    expect(within(summary as HTMLElement).getByText('ENC-1')).toBeInTheDocument();
    expect(within(summary as HTMLElement).getByText('Outpatient')).toBeInTheDocument();
  });

  it('renders formatted birth date and computed age in the header', () => {
    render(<PatientChart {...props} />);

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    expect(within(banner).getByText(/\d+ yrs/)).toBeInTheDocument();
    expect(within(banner).getByText('DOB 03-Feb-1990')).toBeInTheDocument();
    expect(within(banner).getByText('PAT-00000001').closest('.patient-chart__patient-number-badge'))
      .not.toBeNull();
  });

  it('renders the photo or a placeholder', () => {    const { rerender } = render(<PatientChart {...props} />);
    expect(screen.getByRole('img', { name: 'Patient photo placeholder' })).toBeInTheDocument();

    rerender(<PatientChart {...props} photoUrl="/patient/patient-1/photo" />);
    expect(screen.getByRole('img', { name: 'Amina Juma patient photo' })).toHaveAttribute(
      'src',
      '/patient/patient-1/photo',
    );
  });

  it('renders no section nav in content — the contextual sidebar owns navigation', () => {
    render(<PatientChart {...props} />);

    // 15 section links live in the app sidebar (see navigation.test.tsx),
    // never duplicated inside the chart content.
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Allergies' })).not.toBeInTheDocument();
  });

  it('shows an Active visit tag by the patient name when an encounter is active', () => {
    render(
      <PatientChart
        {...props}
        activeEncounter={{
          encounterId: 'encounter-1',
          encounterNumber: 'ENC-1',
          encounterType: 'OUTPATIENT',
          status: 'ACTIVE',
          departmentId: null,
          unitId: null,
          arrivedAt: '2026-09-01T10:00:00Z',
          startedAt: '2026-09-01T10:20:00Z',
        }}
      />,
    );

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    expect(within(banner).getByRole('heading', { name: 'Amina Juma' })).toBeInTheDocument();
    expect(within(banner).getByText('Active visit')).toBeInTheDocument();
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('shows no Active visit tag in the banner without an active encounter', () => {
    render(<PatientChart {...props} />);

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    const nameRow = banner.querySelector('.patient-chart__name-row');
    expect(nameRow).not.toBeNull();
    expect(nameRow).toHaveTextContent('No active visit');
    expect(within(banner).queryByText('Active visit')).not.toBeInTheDocument();
    expect(within(banner).queryByLabelText('Patient details')).toBeInTheDocument();
  });

  it('does not show a restricted-allergies badge in the patient banner', () => {
    render(
      <PatientChart
        {...props}
        activeEncounter={{
          encounterId: 'encounter-1',
          encounterNumber: 'ENC-1',
          encounterType: 'OUTPATIENT',
          status: 'ACTIVE',
          departmentId: null,
          unitId: null,
          arrivedAt: '2026-09-01T10:00:00Z',
          startedAt: null,
        }}
        chart={{ ...props.chart, restricted: ['allergies'] }}
      />,
    );

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    expect(within(banner).queryByText('Allergies restricted')).not.toBeInTheDocument();
    expect(within(banner).queryByText('No active allergies recorded')).not.toBeInTheDocument();
  });

  it('offers walk-in check-in via the Actions menu, not an inline body button', () => {
    render(<PatientChart {...props} />);

    // Inline body check-in was removed once the header Actions menu landed.
    expect(screen.queryByRole('button', { name: 'Check in Amina Juma' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    const checkIn = screen.getByRole('menuitem', { name: 'Check in patient' });
    expect(checkIn).not.toHaveAttribute('aria-disabled', 'true');
    fireEvent.click(checkIn);

    expect(screen.getByRole('dialog', { name: 'Check in patient' })).toBeInTheDocument();
  });

  it('hides check-in in the Actions menu when an active encounter exists', () => {
    render(
      <PatientChart
        {...props}
        activeEncounter={{
          encounterId: 'encounter-1',
          encounterNumber: 'ENC-1',
          encounterType: 'OUTPATIENT',
          status: 'ACTIVE',
          departmentId: null,
          unitId: null,
          arrivedAt: '2026-09-01T10:00:00Z',
          startedAt: '2026-09-01T10:20:00Z',
        }}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    expect(screen.queryByRole('menuitem', { name: 'Check in patient' })).not.toBeInTheDocument();
  });

  it('hides the Actions menu when no patient or workflow actions are available', () => {
    state.canUpdate = false;
    state.canCreate = false;
    render(<PatientChart {...props} />);

    expect(screen.queryByRole('button', { name: 'Actions' })).not.toBeInTheDocument();
  });

  it('opens the active nursing assessment from chart Actions', () => {
    state.canOpenVitalsWorkflow = true;
    render(
      <PatientChart
        {...props}
        activeEncounter={{
          encounterId: 'encounter-1',
          encounterNumber: 'ENC-1',
          encounterType: 'OUTPATIENT',
          status: 'ACTIVE',
          departmentId: null,
          unitId: null,
          arrivedAt: '2026-09-01T10:00:00Z',
          startedAt: '2026-09-01T10:20:00Z',
        }}
        queueEntry={{
          entryId: 'entry-1',
          queueId: 'queue-1',
          serviceCapability: 'TRIAGE',
          ticketNumber: 'T-001',
          priority: 'NORMAL',
          status: 'IN_SERVICE',
        }}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Continue nursing assessment' }));

    expect(state.routerVisit).toHaveBeenCalledWith('/workspaces/nurse-station/entries/entry-1');
  });

  it('opens consultation only when the user has consultation access', () => {
    state.canOpenVitalsWorkflow = true;
    state.canReadConsultations = true;
    render(
      <PatientChart
        {...props}
        activeEncounter={{
          encounterId: 'encounter-1',
          encounterNumber: 'ENC-1',
          encounterType: 'OUTPATIENT',
          status: 'ACTIVE',
          departmentId: null,
          unitId: null,
          arrivedAt: '2026-09-01T10:00:00Z',
          startedAt: '2026-09-01T10:20:00Z',
        }}
        queueEntry={{
          entryId: 'entry-2',
          queueId: 'queue-2',
          serviceCapability: 'CONSULTATION',
          ticketNumber: 'T-002',
          priority: 'NORMAL',
          status: 'IN_SERVICE',
        }}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Continue consultation' }));

    expect(state.routerVisit).toHaveBeenCalledWith('/workspaces/clinician/entries/entry-2');
  });

  it('hides consultation continuation without consultation access', () => {
    state.canOpenVitalsWorkflow = true;
    render(
      <PatientChart
        {...props}
        activeEncounter={{
          encounterId: 'encounter-1',
          encounterNumber: 'ENC-1',
          encounterType: 'OUTPATIENT',
          status: 'ACTIVE',
          departmentId: null,
          unitId: null,
          arrivedAt: '2026-09-01T10:00:00Z',
          startedAt: '2026-09-01T10:20:00Z',
        }}
        queueEntry={{
          entryId: 'entry-2',
          queueId: 'queue-2',
          serviceCapability: 'CONSULTATION',
          ticketNumber: 'T-002',
          priority: 'NORMAL',
          status: 'IN_SERVICE',
        }}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));

    expect(screen.queryByRole('menuitem', { name: 'Continue consultation' })).not.toBeInTheDocument();
    expect(state.routerVisit).not.toHaveBeenCalled();
  });

  it('keeps patient actions in the banner across sections', () => {
    state.canBook = true;
    render(<PatientChart {...props} />);

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    fireEvent.click(within(banner).getByRole('button', { name: 'Actions' }));
    expect(screen.getByRole('menuitem', { name: 'Book appointment' })).not.toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByRole('menuitem', { name: 'Check in patient' })).not.toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByRole('menuitem', { name: 'Edit patient details' })).toBeInTheDocument();
    expect(screen.queryByRole('complementary', { name: 'Quick actions' })).not.toBeInTheDocument();
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('keeps banner actions and one section booking entry on the appointments tab', () => {
    state.url = '/patient/patient-1/chart/appointments';
    state.canBook = true;
    render(<PatientChart {...props} />);

    expect(screen.getAllByRole('button', { name: 'Book appointment' })).toHaveLength(1);
    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    expect(screen.getByRole('menuitem', { name: 'Book appointment' })).not.toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByRole('menuitem', { name: 'Check in patient' })).not.toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByRole('menuitem', { name: 'Edit patient details' })).toBeInTheDocument();
  });

  it('opens walk-in check-in from the Actions menu without an appointment', () => {
    render(<PatientChart {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Check in patient' }));
    const dialog = screen.getByRole('dialog', { name: 'Check in patient' });
    const patient = within(dialog).getByRole('group', { name: 'Selected patient' });
    expect(within(patient).getByText('Amina Juma')).toBeInTheDocument();
    expect(within(patient).getByText('PAT-00000001')).toBeInTheDocument();
    expect(within(dialog).queryByText(/Appointment:/)).not.toBeInTheDocument();
  });

  it('shows the in-panel review only after routing choices are made and requires confirmation', async () => {
    state.canPlaceQueue = true;
    state.getJson.mockResolvedValue({
      facilityId: 'facility-1',
      facilityName: 'Demo',
      departments: [{
        id: 'department-nursing',
        name: 'Nursing',
        code: 'NUR',
        units: [{ id: 'unit-triage', name: 'Triage', code: 'TRI' }],
      }],
    });
    render(
      <PatientChart
        {...props}
        availableQueues={[{
          id: 'queue-triage',
          departmentId: 'department-nursing',
          name: 'Nursing Triage',
          code: 'NUR-TRI',
          serviceCapability: 'TRIAGE',
          roomNames: ['Triage Room 1'],
        }]}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Check in patient' }));
    fireEvent.change(screen.getByLabelText('Encounter type'), { target: { value: 'OUTPATIENT' } });
    expect(screen.queryByRole('region', { name: 'Check-in preview' })).not.toBeInTheDocument();
    await screen.findByLabelText('Department');
    fireEvent.change(screen.getByLabelText('Department'), { target: { value: 'department-nursing' } });
    expect(screen.queryByRole('region', { name: 'Check-in preview' })).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Unit (optional)'), { target: { value: 'unit-triage' } });
    fireEvent.change(screen.getByLabelText('Place into queue (optional)'), { target: { value: 'queue-triage' } });
    expect(screen.queryByRole('region', { name: 'Selected initial destination' })).not.toBeInTheDocument();

    const preview = await screen.findByRole('region', { name: 'Check-in preview' });
    expect(within(preview).getByText('Outpatient')).toBeInTheDocument();
    expect(within(preview).getByText('Nursing')).toBeInTheDocument();
    expect(within(preview).getByText('Triage')).toBeInTheDocument();
    expect(within(preview).getByText('Nursing Triage')).toBeInTheDocument();
    expect(within(preview).getByText('ROUTINE')).toBeInTheDocument();
    expect(within(preview).getByText('Triage Room 1')).toBeInTheDocument();
    const checkInButton = screen.getByRole('button', { name: 'Check in patient' });
    expect(checkInButton).toBeDisabled();
    expect(state.inertiaPost).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('checkbox', { name: 'I’ve reviewed these details and confirm check-in.' }));
    expect(checkInButton).toBeEnabled();

    fireEvent.change(screen.getByLabelText('Priority'), { target: { value: 'URGENT' } });
    await waitFor(() => expect(checkInButton).toBeDisabled());
    fireEvent.click(screen.getByRole('checkbox', { name: 'I’ve reviewed these details and confirm check-in.' }));
    fireEvent.click(checkInButton);

    expect(state.inertiaPost).toHaveBeenCalledWith(
      '/workspaces/reception/check-in',
      { preserveScroll: true },
    );
  });

  it('explicitly confirms check-in without queue placement when no queue is selected', async () => {
    render(<PatientChart {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Check in patient' }));
    fireEvent.change(screen.getByLabelText('Encounter type'), { target: { value: 'OUTPATIENT' } });
    expect(screen.queryByRole('region', { name: 'Selected initial destination' })).not.toBeInTheDocument();
    const preview = await screen.findByRole('region', { name: 'Check-in preview' });
    expect(within(preview).getByText('No queue placement')).toBeInTheDocument();
    expect(state.inertiaPost).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('checkbox', { name: 'I’ve reviewed these details and confirm check-in.' }));
    fireEvent.click(screen.getByRole('button', { name: 'Check in patient' }));
    expect(state.inertiaPost).toHaveBeenCalledWith(
      '/workspaces/reception/check-in',
      { preserveScroll: true },
    );
  });

  it('hides check-in once an active encounter exists while keeping booking available', () => {
    state.canBook = true;
    render(
      <PatientChart
        {...props}
        activeEncounter={{
          encounterId: 'encounter-1',
          encounterNumber: 'ENC-1',
          encounterType: 'OUTPATIENT',
          status: 'ACTIVE',
          departmentId: null,
          unitId: null,
          arrivedAt: '2026-09-01T10:00:00Z',
          startedAt: '2026-09-01T10:20:00Z',
        }}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    expect(screen.queryByRole('menuitem', { name: 'Check in patient' })).not.toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Book appointment' })).not.toHaveAttribute('aria-disabled', 'true');
  });

  it('opens the booking panel from the Actions menu', () => {
    state.canBook = true;
    render(<PatientChart {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Book appointment' }));
    expect(screen.getByRole('dialog', { name: 'Book appointment' })).toBeInTheDocument();
  });

  it('opens a patient action without a discard prompt when no draft is dirty', () => {
    state.canBook = true;
    render(<PatientChart {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Book appointment' }));
    expect(screen.queryByText('Discard unsaved changes?')).not.toBeInTheDocument();
    expect(screen.getByRole('dialog', { name: 'Book appointment' })).toBeInTheDocument();
  });

  it('docks chart workspace panels at the content edge without a quick-action rail', () => {
    state.canBook = true;
    render(<PatientChart {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Book appointment' }));

    const panel = document.querySelector('.afy-dock');
    expect(panel).not.toBeNull();
    expect(panel).toHaveStyle({ right: '0px' });
    expect(screen.queryByRole('complementary', { name: 'Quick actions' })).not.toBeInTheDocument();
  });

  it('wires chart appointment rows with check-in context from the chart', () => {
    state.url = '/patient/patient-1/chart/appointments';
    render(
      <PatientChart
        {...props}
        availableQueues={[{
          id: 'queue-1', departmentId: null, name: 'General OPD', code: 'OPD', serviceCapability: null,
        }]}
        chart={{
          ...props.chart,
          appointments: [
            {
              id: 'appt-1',
              number: 'APT-00000001',
              resourceId: 'res-1',
              appointmentTypeId: 'type-1',
              appointmentTypeName: 'General Consultation',
              resourceName: 'Dr. Asha Mwinyi',
              start: '2030-05-06T09:00:00+03:00',
              end: '2030-05-06T09:30:00+03:00',
              status: 'BOOKED',
              reason: null,
            },
          ],
        }}
      />,
    );

    expect(screen.getByRole('button', { name: 'Check in appointment APT-00000001' })).toBeInTheDocument();
  });

  it('offers canonical View, Reschedule, and Cancel entries on chart appointment rows', () => {
    state.url = '/patient/patient-1/chart/appointments';
    state.canManage = true;
    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          appointments: [
            {
              id: 'appt-1',
              number: 'APT-00000001',
              resourceId: 'res-1',
              appointmentTypeId: 'type-1',
              appointmentTypeName: 'General Consultation',
              resourceName: 'Dr. Asha Mwinyi',
              start: '2030-05-06T09:00:00+03:00',
              end: '2030-05-06T09:30:00+03:00',
              status: 'BOOKED',
              reason: null,
            },
          ],
        }}
      />,
    );

    expect(screen.getByText('General Consultation')).toBeInTheDocument();
    expect(screen.getByText('Dr. Asha Mwinyi')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'View' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reschedule' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
  });

  it('hides Cancel for appointments outside cancellable lifecycle states', () => {
    state.url = '/patient/patient-1/chart/appointments';
    state.canManage = true;
    const nonCancellableStatuses = ['ARRIVED', 'IN_SERVICE', 'COMPLETED', 'CANCELLED', 'RESCHEDULED', 'NO_SHOW'];

    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          appointments: nonCancellableStatuses.map((status, index) => ({
            id: `appt-${index}`,
            number: `APT-${index}`,
            resourceId: 'res-1',
            appointmentTypeId: 'type-1',
            appointmentTypeName: 'General Consultation',
            resourceName: 'Dr. Asha Mwinyi',
            start: '2030-05-06T09:00:00+03:00',
            end: '2030-05-06T09:30:00+03:00',
            status,
            reason: null,
          })),
        }}
      />,
    );

    expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
  });

  it('keeps View but hides Reschedule and Cancel without manage permission', () => {
    state.url = '/patient/patient-1/chart/appointments';
    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          appointments: [
            {
              id: 'appt-1',
              number: 'APT-00000001',
              resourceId: 'res-1',
              appointmentTypeId: 'type-1',
              appointmentTypeName: 'General Consultation',
              resourceName: 'Dr. Asha Mwinyi',
              start: '2030-05-06T09:00:00+03:00',
              end: '2030-05-06T09:30:00+03:00',
              status: 'BOOKED',
              reason: null,
            },
          ],
        }}
      />,
    );

    expect(screen.getByRole('button', { name: 'View' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reschedule' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
  });

  it('opens the canonical details panel from a chart appointment row', () => {
    state.url = '/patient/patient-1/chart/appointments';
    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          appointments: [
            {
              id: 'appt-1',
              number: 'APT-00000001',
              resourceId: 'res-1',
              appointmentTypeId: 'type-1',
              appointmentTypeName: 'General Consultation',
              resourceName: 'Dr. Asha Mwinyi',
              start: '2030-05-06T09:00:00+03:00',
              end: '2030-05-06T09:30:00+03:00',
              status: 'BOOKED',
              reason: 'Review visit',
            },
          ],
        }}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'View' }));
    const dialog = screen.getByRole('dialog', { name: 'Appointment APT-00000001' });
    expect(within(dialog).getByRole('link', { name: 'Amina Juma' })).toHaveAttribute(
      'href',
      '/patient/patient-1/chart',
    );
    expect(within(dialog).getByText('General Consultation')).toBeInTheDocument();
    expect(within(dialog).getByText('Dr. Asha Mwinyi')).toBeInTheDocument();
    expect(within(dialog).getByText(/Review visit/)).toBeInTheDocument();
  });

  it('opens the canonical reschedule and cancel dialogs from chart appointment rows', () => {
    state.url = '/patient/patient-1/chart/appointments';
    state.canManage = true;
    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          appointments: [
            {
              id: 'appt-1',
              number: 'APT-00000001',
              resourceId: 'res-1',
              appointmentTypeId: 'type-1',
              appointmentTypeName: 'General Consultation',
              resourceName: 'Dr. Asha Mwinyi',
              start: '2030-05-06T09:00:00+03:00',
              end: '2030-05-06T09:30:00+03:00',
              status: 'BOOKED',
              reason: null,
            },
          ],
        }}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Reschedule' }));
    expect(screen.getByRole('dialog', { name: 'Reschedule appointment' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Keep date' }));

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByRole('dialog', { name: 'Cancel appointment' })).toBeInTheDocument();
  });

  it('offers department and unit routing from facility structure', async () => {
    state.getJson.mockResolvedValueOnce({
      facilityId: 'facility-1',
      facilityName: 'Demo',
      departments: [{
        id: 'dept-1', name: 'Outpatient', code: 'OPD',
        units: [{ id: 'unit-1', name: 'Room 1', code: 'R1' }],
      }],
    });
    render(<PatientChart {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Check in patient' }));
    expect(screen.getByRole('dialog', { name: 'Check in patient' })).toBeInTheDocument();
    const encounterType = screen.getByRole('combobox', { name: 'Encounter type' });
    fireEvent.change(encounterType, { target: { value: 'OUTPATIENT' } });
    const department = await screen.findByRole('combobox', { name: 'Department' });
    expect(department).toBeInTheDocument();
    expect(screen.queryByRole('combobox', { name: 'Unit (optional)' })).not.toBeInTheDocument();

    fireEvent.change(department, { target: { value: 'dept-1' } });
    expect(await screen.findByRole('combobox', { name: 'Unit (optional)' })).toBeInTheDocument();
  });

  it('selects and renders a populated deep-linked section', () => {
    state.url = '/patient/patient-1/chart/allergies';
    render(<PatientChart {...props} />);

    expect(screen.getByRole('region', { name: 'Allergies' })).toBeInTheDocument();
    const allergies = screen.getByRole('region', { name: 'Allergies' });
    expect(within(allergies).getByText('Penicillin')).toBeInTheDocument();
    expect(within(allergies).getByText('Rash')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Patient details' })).not.toBeInTheDocument();
  });

  it('links medications to Pharmacy only when the prescription belongs to the active facility', () => {
    state.url = '/patient/patient-1/chart/medications';
    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          medications: [
            {
              id: 'rx-active-facility',
              facilityId: 'facility-1',
              number: 'RX-00000001',
              medication: 'Amoxicillin',
              dose: '500 mg',
              frequency: 'Twice daily',
              status: 'ACTIVE',
              recordedAt: '2026-10-01T10:00:00Z',
            },
            {
              id: 'rx-other-facility',
              facilityId: 'facility-2',
              number: 'RX-00000002',
              medication: 'Paracetamol',
              dose: '500 mg',
              frequency: 'As needed',
              status: 'ACTIVE',
              recordedAt: '2026-10-02T10:00:00Z',
            },
          ],
        }}
      />,
    );

    expect(screen.getByRole('link', { name: 'Open prescription in Pharmacy' }))
      .toHaveAttribute('href', '/workspaces/pharmacy?view=prescriptions&rx_id=rx-active-facility');
    expect(screen.getByText('This prescription belongs to another facility.')).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: 'Open prescription in Pharmacy' })).toHaveLength(1);
  });

  it('renders the shared EmptyState for an empty selected section', () => {
    state.url = '/patient/patient-1/chart/conditions';
    render(<PatientChart {...props} />);

    expect(screen.getByText('No problems recorded')).toBeInTheDocument();
  });

  it('selects and renders populated visits from encounter history', () => {
    state.url = '/patient/patient-1/chart/visits';
    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          visits: [
            {
              id: 'encounter-1',
              number: 'ENC-00000001',
              encounterType: 'OUTPATIENT',
              status: 'COMPLETED',
              disposition: 'DISCHARGED',
              departmentId: 'dept-1',
              departmentName: 'Outpatient',
              unitId: 'unit-1',
              unitName: 'Room 1',
              arrivedAt: '2026-09-01T10:00:00Z',
              startedAt: '2026-09-01T10:20:00Z',
              admittedAt: null,
              completedAt: '2026-09-01T11:00:00Z',
            },
          ],
        }}
      />,
    );

    const visits = screen.getByRole('region', { name: 'Visits' });
    expect(within(visits).getByText('ENC-00000001')).toBeInTheDocument();
    expect(within(visits).getAllByText('Outpatient')).toHaveLength(2);
    expect(within(visits).getByText('Room 1')).toBeInTheDocument();
  });

  it('renders the shared EmptyState for visits without history', () => {
    state.url = '/patient/patient-1/chart/visits';
    render(<PatientChart {...props} />);

    expect(screen.getByRole('region', { name: 'Visits' })).toBeInTheDocument();
    expect(screen.getByText('No visits recorded')).toBeInTheDocument();
  });

  it('renders billing account context and humanized ledger entries', () => {
    state.url = '/patient/patient-1/chart/billing-history';
    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          billing: {
            account: { accountNumber: 'FA-1', status: 'ACTIVE', currency: 'TZS' },
            outstanding: {
              financialAccountId: 'account-1',
              currency: 'TZS',
              chargedTotal: '100.00',
              paidTotal: '40.00',
              writeOffTotal: '0.00',
              adjustmentTotal: '0.00',
              settledTotal: '40.00',
              outstanding: '60.00',
              entryCount: 1,
            },
            ledger: [{
              entryType: 'CHARGE',
              direction: 'DEBIT',
              amount: '100.00',
              currency: 'TZS',
              postedAt: '2026-09-01T10:00:00Z',
            }],
          },
        }}
      />,
    );

    const billing = screen.getByRole('region', { name: 'Billing' });
    expect(within(billing).getByText('FA-1')).toBeInTheDocument();
    expect(within(billing).getByText('60.00 TZS')).toBeInTheDocument();
    expect(within(billing).getByText('Charge')).toBeInTheDocument();
    expect(within(billing).getByText('Debit')).toBeInTheDocument();
  });

  it('renders lab reference ranges and released result status', () => {
    state.url = '/patient/patient-1/chart/lab-results';
    render(
      <PatientChart
        {...props}
        chart={{
          ...props.chart,
          labOrders: [{
            orderId: 'lab-order-1',
            orderNumber: 'LAB-1',
            priority: 'ROUTINE',
            status: 'COMPLETED',
            createdAt: '2026-09-01T10:00:00Z',
            items: [{ testCode: 'HGB', testName: 'Hemoglobin', status: 'COMPLETED' }],
          }, {
            orderId: 'lab-order-old',
            orderNumber: 'LAB-OLD',
            priority: 'ROUTINE',
            status: 'COMPLETED',
            createdAt: '2025-09-01T10:00:00Z',
            items: [{ testCode: 'WBC', testName: 'White blood cells', status: 'COMPLETED' }],
          }],
          labResults: {
            'lab-order-1': [{
              id: 'lab-result-1',
              parameter: 'Hemoglobin',
              value: '9.5',
              unit: 'g/dL',
              flag: 'LOW',
              referenceLow: '12',
              referenceHigh: '16',
              referenceText: null,
              status: 'FINAL',
              releasedAt: '2026-09-02T10:00:00Z',
            }],
          },
        }}
      />,
    );

    const results = screen.getByRole('region', { name: 'Lab results' });
    expect(within(results).getByText('12 – 16')).toBeInTheDocument();
    expect(within(results).getByText('Final')).toBeInTheDocument();
    expect(within(results).getByText('Low')).toBeInTheDocument();
    expect(within(results).getByText('Results not loaded')).toBeInTheDocument();
  });

  it('renders the restricted state for a restricted section', () => {
    state.url = '/patient/patient-1/chart/medications';
    render(<PatientChart {...props} chart={{ ...props.chart, restricted: ['medications'] }} />);

    expect(screen.getByText('Restricted for your role')).toBeInTheDocument();
    expect(screen.queryByText('No medications recorded')).not.toBeInTheDocument();
  });

  it('renders the unavailable state for an unavailable section', () => {
    state.url = '/patient/patient-1/chart/imaging';
    render(<PatientChart {...props} chart={{ ...props.chart, unavailable: ['orders'] }} />);

    expect(screen.getByRole('alert')).toHaveTextContent('Section temporarily unavailable');
    expect(screen.queryByText('No imaging orders')).not.toBeInTheDocument();
  });
});
