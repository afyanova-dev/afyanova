import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PatientChartProps } from '@/features/patients/types/chart';
import { PatientChart } from '@/features/patients/components/PatientChart';
import PatientProfile from '@/pages/Patients/Profile';

const state = vi.hoisted(() => ({
  url: '/patient/patient-1/chart',
  canRead: true,
  canBook: false,
  canManage: false,
  canUpdate: true,
  canCreate: true,
  getJson: vi.fn(),
  sendJson: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');
  return {
    Link: ({ children, href, ...props }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
      <a href={href} {...props}>{children}</a>
    ),
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
        post: vi.fn(),
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
    patient: { read: 'patient.patients.read', update: 'patient.patients.update' },
    scheduling: { book: 'sch.appointment.book', manage: 'sch.appointment.manage', recordArrival: 'sch.appointment.record_arrival' },
    encounter: { create: 'encounter.encounters.create', update: 'encounter.encounters.update' },
    queue: { ticketTransfer: 'queue.ticket.transfer' },
    organization: { structureRead: 'organization.structure.read' },
  },
  useCan: () => (permission: string) => {
    if (permission === 'patient.patients.read') return state.canRead;
    if (permission === 'sch.appointment.book') return state.canBook;
    if (permission === 'sch.appointment.manage') return state.canManage;
    if (permission === 'patient.patients.update') return state.canUpdate;
    if (permission === 'encounter.encounters.create') return state.canCreate;
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
  state.canCreate = true;
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

    expect(screen.getByRole('heading', { name: 'Amina Juma' })).toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: 'Breadcrumb' })).not.toBeInTheDocument();
  });

  it('renders the patient header and default Patient Summary selection', () => {
    render(<PatientChart {...props} />);

    expect(screen.getByRole('heading', { name: 'Amina Juma' })).toBeInTheDocument();
    expect(screen.getByText('Patient number: PAT-00000001')).toBeInTheDocument();
    expect(screen.getByText('Female')).toBeInTheDocument();
    const banner = screen.getByRole('banner', { name: 'Patient header' });
    expect(within(banner).getByText('03-Feb-1990')).toBeInTheDocument();
    expect(within(banner).getByText(/\d+ yrs/)).toBeInTheDocument();
    expect(screen.queryByText('Phone: +255700000000')).not.toBeInTheDocument();
    expect(screen.queryByText('Email: amina@example.test')).not.toBeInTheDocument();
    expect(screen.getByText('Female').querySelector('svg')).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Patient Summary' })).not.toBeInTheDocument();
    expect(document.querySelector('.patient-chart__overview-grid')).not.toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: 'Patient chart sections' })).not.toBeInTheDocument();
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
    await waitFor(() => expect(stickyNoteButton).toHaveAttribute('aria-busy', 'false'));
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
    expect(stickyNoteButton.querySelector('.patient-sticky-note__badge')).toHaveTextContent('1');
  });

  it('expands and collapses additional patient details from the banner', () => {
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
    const showMore = within(banner).getByRole('button', { name: 'Show more' });
    expect(showMore).toHaveAttribute('aria-expanded', 'false');
    expect(within(banner).queryByText('Phone')).not.toBeInTheDocument();

    fireEvent.click(showMore);

    expect(within(banner).getByRole('button', { name: 'Show less' })).toHaveAttribute(
      'aria-expanded',
      'true',
    );
    expect(within(banner).getByText('Phone')).toBeInTheDocument();
    expect(within(banner).getByText('+255700000000')).toBeInTheDocument();
    expect(within(banner).getByText('Email')).toBeInTheDocument();
    expect(within(banner).getByText('NIDA-123 · Verified')).toBeInTheDocument();
    expect(within(banner).getByText('Mwai Kibaki Road, Mikocheni, Kinondoni, Dar es Salaam')).toBeInTheDocument();

    fireEvent.click(within(banner).getByRole('button', { name: 'Show less' }));

    expect(within(banner).getByRole('button', { name: 'Show more' })).toHaveAttribute(
      'aria-expanded',
      'false',
    );
    expect(within(banner).queryByText('Phone')).not.toBeInTheDocument();
  });

  it('uses a labelled Actions menu button with icon-enabled items', () => {
    render(<PatientChart {...props} />);

    const banner = screen.getByRole('banner', { name: 'Patient header' });
    const actionsButton = within(banner).getByRole('button', { name: 'Actions' });
    expect(actionsButton).toHaveTextContent('Actions');
    expect(actionsButton.querySelector('svg')).toBeInTheDocument();

    fireEvent.click(actionsButton);

    expect(screen.getByRole('menuitem', { name: 'Edit patient details' }).querySelector('svg')).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Mark patient deceased' }).querySelector('svg')).toBeInTheDocument();
  });

  it('shows Book appointment in the Appointments section when booking is allowed', () => {
    state.url = '/patient/patient-1/chart/appointments';
    state.canBook = true;
    render(<PatientChart {...props} />);

    expect(screen.getByRole('region', { name: 'Appointments' })).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Book appointment' })).toHaveLength(2);
  });

  it('hides Book appointment from the Appointments section without booking permission', () => {
    state.url = '/patient/patient-1/chart/appointments';
    render(<PatientChart {...props} />);

    expect(screen.getByRole('region', { name: 'Appointments' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Book appointment' })).not.toBeInTheDocument();
  });

  it('shows visit status and encounter type in the two-line patient banner', () => {
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

    expect(screen.getByText('Active visit')).toBeInTheDocument();
    expect(screen.getByText('Outpatient')).toBeInTheDocument();
    expect(screen.getByText('Arrived')).toBeInTheDocument();
    expect(screen.queryByText('Active encounter ENC-1')).not.toBeInTheDocument();
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
    expect(within(banner).getByText('03-Feb-1990')).toBeInTheDocument();
    expect(within(banner).getByText('Patient number: PAT-00000001')).toBeInTheDocument();
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

    // 13 section links live in the app sidebar (see navigation.test.tsx),
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

    expect(screen.getByRole('heading', { name: 'Amina Juma' })).toBeInTheDocument();
    expect(screen.getByText('Active visit')).toBeInTheDocument();
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('shows no Active visit tag without an active encounter', () => {
    render(<PatientChart {...props} />);

    expect(screen.queryByText('Active visit')).not.toBeInTheDocument();
  });

  it('offers walk-in check-in via Actions menu and rail, not an inline body button', () => {
    render(<PatientChart {...props} />);

    // Inline body check-in was removed once the header Actions menu landed —
    // entry is Actions > Add visit plus the Quick actions rail icon.
    expect(screen.queryByRole('button', { name: 'Check in Amina Juma' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Actions/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Check in' })).toBeInTheDocument();
  });

  it('shows no inline check-in button when an active encounter exists', () => {
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

    expect(screen.queryByRole('button', { name: 'Check in Amina Juma' })).not.toBeInTheDocument();
  });

  it('renders the action rail constant across tabs — never hidden by section', () => {
    state.canBook = true;
    render(<PatientChart {...props} />);

    expect(screen.getByRole('complementary', { name: 'Quick actions' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Book appointment' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Check in' })).toBeInTheDocument();
    // Inline overview Edit button was removed with the Actions menu — only the rail icon remains.
    expect(screen.getAllByRole('button', { name: 'Edit demographics' })).toHaveLength(1);
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('keeps the full rail on the appointments tab', () => {
    state.url = '/patient/patient-1/chart/appointments';
    state.canBook = true;
    render(<PatientChart {...props} />);

    expect(screen.getAllByRole('button', { name: 'Book appointment' })).toHaveLength(2);
    expect(screen.getByRole('button', { name: 'Edit demographics' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Check in' })).toBeInTheDocument();
  });

  it('opens walk-in check-in from the rail without an appointment', () => {
    render(<PatientChart {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Check in' }));
    const dialog = screen.getByRole('dialog', { name: 'Check in patient' });
    const patient = within(dialog).getByRole('group', { name: 'Selected patient' });
    expect(within(patient).getByText('Amina Juma')).toBeInTheDocument();
    expect(within(patient).getByText('PAT-00000001')).toBeInTheDocument();
    expect(within(dialog).queryByText(/Appointment:/)).not.toBeInTheDocument();
  });

  it('hides rail check-in once an active encounter exists', () => {
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

    expect(screen.queryByRole('button', { name: 'Check in' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Book appointment' })).toBeInTheDocument();
  });

  it('toggles the booking dialog from the rail icon', () => {
    state.canBook = true;
    render(<PatientChart {...props} />);

    const railIcon = (): HTMLElement => {
      const rail = screen.getByRole('complementary', { name: 'Quick actions' });
      const icon = within(rail)
        .getAllByRole('button', { name: 'Book appointment' })
        .find((button) => button.className.includes('icon-only'));
      if (!icon) throw new Error('rail book icon not rendered');
      return icon;
    };

    fireEvent.click(railIcon());
    expect(screen.getByRole('dialog', { name: 'Book appointment' })).toBeInTheDocument();

    fireEvent.click(railIcon());
    expect(screen.queryByRole('dialog', { name: 'Book appointment' })).not.toBeInTheDocument();
  });

  it('switches pristine workspaces freely without a discard prompt', () => {
    state.canBook = true;
    render(<PatientChart {...props} />);

    const rail = screen.getByRole('complementary', { name: 'Quick actions' });
    const railIcon = (name: string): HTMLElement => {
      const icon = within(rail)
        .getAllByRole('button', { name })
        .find((button) => button.className.includes('icon-only'));
      if (!icon) throw new Error(`rail ${name} icon not rendered`);
      return icon;
    };

    fireEvent.click(railIcon('Edit demographics'));
    expect(screen.getByRole('dialog', { name: 'Edit demographics' })).toBeInTheDocument();

    // Nothing typed: switching straight through, no modal.
    fireEvent.click(railIcon('Book appointment'));
    expect(screen.queryByText('Discard unsaved changes?')).not.toBeInTheDocument();
    expect(screen.getByRole('dialog', { name: 'Book appointment' })).toBeInTheDocument();
    expect(screen.queryByRole('dialog', { name: 'Edit demographics' })).not.toBeInTheDocument();
  });

  it('insets chart workspace panels beside the rail instead of covering it', () => {
    state.canBook = true;
    render(<PatientChart {...props} />);

    const rail = screen.getByRole('complementary', { name: 'Quick actions' });
    const icon = within(rail)
      .getAllByRole('button', { name: 'Book appointment' })
      .find((button) => button.className.includes('icon-only'));
    if (!icon) throw new Error('rail book icon not rendered');
    fireEvent.click(icon);

    const panel = document.querySelector('.afy-dock');
    expect(panel).not.toBeNull();
    expect(panel).toHaveStyle({ right: '3rem' });
    expect(rail.compareDocumentPosition(panel as Node) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
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

    expect(screen.getByText(/General Consultation · Dr. Asha Mwinyi/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'View' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reschedule' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
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

    fireEvent.click(screen.getByRole('button', { name: 'Check in' }));
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
    expect(screen.getByRole('heading', { name: 'Penicillin' })).toBeInTheDocument();
    expect(screen.getByText('Rash')).toBeInTheDocument();
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

    expect(screen.getByText('No conditions to display')).toBeInTheDocument();
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

    expect(screen.getByRole('region', { name: 'Visits' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'ENC-00000001' })).toBeInTheDocument();
    expect(screen.getByText('Outpatient')).toBeInTheDocument();
    expect(screen.getByText('Room 1')).toBeInTheDocument();
  });

  it('renders the shared EmptyState for visits without history', () => {
    state.url = '/patient/patient-1/chart/visits';
    render(<PatientChart {...props} />);

    expect(screen.getByRole('region', { name: 'Visits' })).toBeInTheDocument();
    expect(screen.getByText('No visits to display')).toBeInTheDocument();
  });

  it('renders the restricted state for a restricted section', () => {
    state.url = '/patient/patient-1/chart/medications';
    render(<PatientChart {...props} chart={{ ...props.chart, restricted: ['medications'] }} />);

    expect(screen.getByText('Restricted section')).toBeInTheDocument();
    expect(screen.queryByText('No medications to display')).not.toBeInTheDocument();
  });

  it('renders the shared ErrorState for an unavailable section', () => {
    state.url = '/patient/patient-1/chart/imaging';
    render(<PatientChart {...props} chart={{ ...props.chart, unavailable: ['orders'] }} />);

    expect(screen.getByRole('alert')).toHaveTextContent('Orders unavailable');
    expect(screen.queryByText('No imaging to display')).not.toBeInTheDocument();
  });
});
