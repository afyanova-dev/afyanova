import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { routes } from '@/app/routes';
import NurseStationShow from '@/pages/NurseStation/Show';
import type { NurseStationShowProps } from '@/features/nurse-station/types';

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');

  return {
    Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
      <a href={href} {...rest}>{children}</a>
    ),
    router: { post: vi.fn() },
    useForm: (initial: Record<string, unknown>) => {
      const [data, setState] = React.useState(initial);

      return {
        data,
        setData: (key: string, value: unknown) => setState((current) => ({ ...current, [key]: value })),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
      };
    },
  };
});

vi.mock('@/layouts/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/features/clinical/QueuePatientBanner', () => ({
  QueuePatientBanner: () => null,
}));

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

function nurseStationProps(overrides: Partial<NurseStationShowProps> = {}): NurseStationShowProps {
  return {
    entry: {
      id: 'triage-ticket-1',
      ticketNumber: 'T-001',
      queue: 'OPD Triage',
      priority: 'ROUTINE',
      status: 'WAITING',
    },
    queueNavigation: { position: 1, total: 1, previous: null, next: null },
    patient: {
      patientId: 'patient-1',
      patientNumber: 'PAT-001',
      displayName: 'Amina Juma',
      dateOfBirth: '1990-01-01',
      sex: 'FEMALE',
      status: 'ACTIVE',
      isProvisional: false,
      photoUrl: null,
    },
    encounter: {
      encounterId: 'encounter-1',
      status: 'ACTIVE',
      type: 'OUTPATIENT',
    },
    vitals: [],
    triage: null,
    canRecordVitals: true,
    canRecordTriage: true,
    canStartService: false,
    canCall: true,
    callRooms: [],
    consultationQueues: [],
    canHandoff: false,
    ...overrides,
  };
}

describe('nurse station call patient', () => {
  beforeEach(() => {
    vi.mocked(router.post).mockClear();
  });

  it('calls the waiting patient from the alert without a service location when none is configured', () => {
    render(<NurseStationShow {...nurseStationProps()} />);

    expect(screen.queryByLabelText('Call to')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Call patient' }));

    expect(router.post).toHaveBeenCalledWith(
      routes.workspaces.ticketCall('triage-ticket-1'),
      expect.objectContaining({ room: null }),
      expect.anything(),
    );
  });

  it('offers callable locations and posts the selected location', () => {
    render(
      <NurseStationShow
        {...nurseStationProps({
          callRooms: [
            { id: 'room-1', name: 'Triage Room', code: 'TRIAGE-1' },
            { id: 'room-2', name: 'Second Triage Room', code: 'TRIAGE-2' },
          ],
        })}
      />,
    );

    fireEvent.change(screen.getByLabelText('Call to'), { target: { value: 'room-2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Call patient' }));

    expect(router.post).toHaveBeenCalledWith(
      routes.workspaces.ticketCall('triage-ticket-1'),
      expect.objectContaining({ room: 'room-2' }),
      expect.anything(),
    );
  });

  it('keeps the informational alert without a call action when calling is not permitted', () => {
    render(<NurseStationShow {...nurseStationProps({ canCall: false })} />);

    expect(screen.queryByRole('button', { name: 'Call patient' })).not.toBeInTheDocument();
    expect(screen.getByText(/not yet been called to the room/i)).toBeInTheDocument();
  });

  it('confirms the called patient from the arrival alert', () => {
    render(
      <NurseStationShow
        {...nurseStationProps({
          entry: {
            id: 'triage-ticket-1',
            ticketNumber: 'T-001',
            queue: 'OPD Triage',
            priority: 'ROUTINE',
            status: 'CALLED',
          },
          canStartService: true,
        })}
      />,
    );

    const alert = screen.getByText('Confirm the patient is present').closest('.cds--actionable-notification');
    expect(alert).not.toBeNull();
    fireEvent.click(within(alert as HTMLElement).getByRole('button', { name: 'Confirm patient present' }));

    expect(router.post).toHaveBeenCalledWith(
      routes.workspaces.nurseStationStart('triage-ticket-1'),
      { return_to: routes.workspaces.nurseStationEntry('triage-ticket-1') },
      expect.anything(),
    );
  });

  it('shows only the arrival guidance when the patient cannot be confirmed', () => {
    render(
      <NurseStationShow
        {...nurseStationProps({
          entry: {
            id: 'triage-ticket-1',
            ticketNumber: 'T-001',
            queue: 'OPD Triage',
            priority: 'ROUTINE',
            status: 'CALLED',
          },
          canStartService: false,
        })}
      />,
    );

    expect(screen.queryByRole('button', { name: 'Confirm patient present' })).not.toBeInTheDocument();
    expect(screen.getByText(/confirm they have arrived before starting triage assessment/i)).toBeInTheDocument();
  });
});

describe('nurse station assessment workspace', () => {
  it('groups systolic and diastolic inputs under blood pressure', () => {
    render(
      <NurseStationShow
        {...nurseStationProps({
          entry: {
            id: 'triage-ticket-1',
            ticketNumber: 'T-001',
            queue: 'OPD Triage',
            priority: 'ROUTINE',
            status: 'IN_SERVICE',
          },
        })}
      />,
    );

    const bloodPressureGroup = screen.getByRole('group', { name: 'Blood pressure (mmHg)' });

    expect(within(bloodPressureGroup).getByText('Blood pressure (mmHg)')).toHaveClass('cds--label');
    expect(within(bloodPressureGroup).getByRole('spinbutton', { name: 'Systolic' })).toBeInTheDocument();
    expect(within(bloodPressureGroup).getByRole('spinbutton', { name: 'Diastolic' })).toBeInTheDocument();
    expect(
      bloodPressureGroup.compareDocumentPosition(
        screen.getByRole('spinbutton', { name: 'Oxygen saturation (%)' }),
      ),
    ).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
  });

  it('shows permission-filtered vertical tabs and switches between assessment panels', () => {
    render(
      <NurseStationShow
        {...nurseStationProps({
          entry: {
            id: 'triage-ticket-1',
            ticketNumber: 'T-001',
            queue: 'OPD Triage',
            priority: 'ROUTINE',
            status: 'IN_SERVICE',
          },
          canHandoff: false,
        })}
      />,
    );

    const workspaceTabs = screen.getByRole('tablist', { name: 'Nursing assessment workspace' });
    expect(within(workspaceTabs).getByRole('tab', { name: 'Vital signs' })).toHaveAttribute('aria-selected', 'true');
    expect(within(workspaceTabs).getByRole('tab', { name: 'Triage' })).toBeInTheDocument();
    expect(within(workspaceTabs).queryByRole('tab', { name: 'Handoff' })).not.toBeInTheDocument();

    fireEvent.click(within(workspaceTabs).getByRole('tab', { name: 'Triage' }));

    expect(screen.getByRole('heading', { name: 'Record triage assessment' })).toBeInTheDocument();
    expect(within(workspaceTabs).getByRole('tab', { name: 'Triage' })).toHaveAttribute('aria-selected', 'true');
  });

  it('keeps context, triage, and recent vitals in separate sidebar panels', () => {
    const { container } = render(<NurseStationShow {...nurseStationProps()} />);

    const sidebar = screen.getByRole('complementary', { name: 'Visit context' });
    const workbench = screen.getByRole('heading', { name: 'Record vital signs' }).closest('.nurse-station-workspace__workbench');

    expect(container.querySelector('.clinical-entry-workspace')).toHaveClass('nurse-station-workspace');
    expect(container.querySelector('.clinical-entry-workspace__sidebar')).toBe(sidebar);
    expect(container.querySelector('.clinical-entry-workspace__workbench')).toBe(workbench);
    expect(container.querySelector('.clinical-entry-workspace-navigation')).toBeInTheDocument();
    expect(container.querySelector('.clinical-entry-tab-content')).toBeInTheDocument();
    expect(within(sidebar).getByRole('heading', { name: 'Visit context' })).toBeInTheDocument();
    expect(within(sidebar).getByRole('heading', { name: 'Current triage' })).toBeInTheDocument();
    expect(within(sidebar).getByRole('heading', { name: 'Recent vitals' })).toBeInTheDocument();
    expect(within(sidebar).getAllByRole('region')).toHaveLength(3);
    expect(workbench).not.toBeNull();
    expect(sidebar).not.toContainElement(workbench as HTMLElement);
  });

  it('presents visit context, triage priority, and recent measurements as clear summaries', () => {
    render(
      <NurseStationShow
        {...nurseStationProps({
          entry: {
            id: 'triage-ticket-1',
            ticketNumber: 'T-001',
            queue: 'OPD Triage',
            priority: 'ROUTINE',
            status: 'IN_SERVICE',
          },
          vitals: [{
            temperature: 37.2,
            heartRate: 82,
            respiratoryRate: 16,
            systolicBp: 120,
            diastolicBp: 80,
            oxygenSaturation: 98,
            weight: 64,
            height: 165,
            painScore: 2,
            occurredAt: '2026-11-05T10:00:00+03:00',
            authoredContext: 'NURSING',
          }],
          triage: {
            chiefComplaint: 'Fever and cough',
            priority: 'URGENT',
            acuity: 'Moderate',
            consciousness: 'Alert',
            occurredAt: '2026-11-05T10:00:00+03:00',
          },
        })}
      />,
    );

    const visitContext = screen.getByRole('region', { name: 'Visit context' });
    const currentTriage = screen.getByRole('region', { name: 'Current triage' });
    const recentVitals = screen.getByRole('region', { name: 'Recent vitals' });

    expect(within(visitContext).getByText('Ticket T-001')).toBeInTheDocument();
    expect(within(visitContext).getByText('OPD Triage')).toBeInTheDocument();
    expect(within(currentTriage).getByText('URGENT')).toBeInTheDocument();
    expect(within(currentTriage).getByText('Fever and cough')).toBeInTheDocument();
    expect(within(recentVitals).getByText('37.2')).toBeInTheDocument();
    expect(within(recentVitals).getByText('°C')).toBeInTheDocument();
    expect(within(recentVitals).getByText('120/80')).toBeInTheDocument();
    expect(within(recentVitals).getByText('mmHg')).toBeInTheDocument();
  });
});
