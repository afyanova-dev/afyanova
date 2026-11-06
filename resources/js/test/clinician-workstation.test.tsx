import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import { routes } from '@/app/routes';
import { sendJson } from '@/lib/http/json';
import ClinicianShow from '@/pages/Clinician/Show';
import type { ClinicianShowProps } from '@/features/clinician/types';
import {
  CUSTOM_MEDICATION_DURATION,
  OTHER_MEDICATION_OPTION,
} from '@/features/clinical/medication-order-options';

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');
  return {
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
      <a href={href}>{children}</a>
    ),
    router: { post: vi.fn(), on: vi.fn(() => vi.fn()) },
    useForm: (initial: Record<string, unknown>) => {
      const [data, setState] = React.useState(initial);
      return {
        data,
        setData: (key: string, value: unknown) => {
          setState((current) => ({ ...current, [key]: value }));
        },
        post: vi.fn((_url: string, options?: { onSuccess?: () => void; onFinish?: () => void }) => {
          options?.onSuccess?.();
          options?.onFinish?.();
        }),
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

vi.mock('@/lib/http/json', () => ({
  sendJson: vi.fn().mockResolvedValue({ savedAt: '2026-11-02T12:00:00+03:00' }),
}));

vi.mock('@/components/ui/SearchableComboBox', () => ({
  SearchableComboBox: ({
    id,
    titleText,
    items,
    selectedId,
    onChange,
  }: {
    id: string;
    titleText: string;
    items: Array<{ id: string; label: string }>;
    selectedId: string;
    onChange: (id: string) => void;
  }) => (
    <label htmlFor={id}>
      {titleText}
      <select id={id} aria-label={titleText} value={selectedId} onChange={(event) => onChange(event.target.value)}>
        <option value="">Select a diagnosis</option>
        {items.map((item) => <option key={item.id} value={item.id}>{item.label}</option>)}
      </select>
    </label>
  ),
}));

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

const baseProps: ClinicianShowProps = {
  entry: {
    id: 'entry-1',
    ticketNumber: 'A001',
    queue: 'General consultation',
    priority: 'ROUTINE',
    status: 'IN_SERVICE',
  },
  callRooms: [],
  canCall: false,
  canStartService: false,
  queueNavigation: {
    position: 2,
    total: 3,
    previous: { id: 'entry-0', ticketNumber: 'A000' },
    next: { id: 'entry-2', ticketNumber: 'A002' },
  },
  patient: {
    patientId: 'patient-1',
    patientNumber: 'PAT-0001',
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
  triage: null,
  vitals: [],
  procedureDefinitions: [],
  labTestCatalog: [],
  labPanelCatalog: [],
  procedures: [],
  prescribingDecisions: [],
  medicationCatalog: [
    {
      id: 'med-1',
      code: 'MED-AMOX-250',
      name: 'Amoxicillin 250 mg',
      unitOfMeasure: 'capsule',
      category: 'ANTIBIOTIC',
    },
  ],
  diagnosisCatalog: [
    {
      id: 'diagnosis-malaria',
      code: 'B54',
      codeSystem: 'ICD-10',
      name: 'Unspecified malaria',
      aliases: 'Malaria fever',
      category: 'INFECTIOUS',
    },
  ],
  notes: [],
  diagnoses: [],
  plans: [],
  allergies: [],
  problems: [],
  medications: [],
  drafts: {
    note: null,
    diagnosis: null,
    plan: null,
  },
  canSignNotes: true,
  canAmendNotes: true,
  canReadNotes: true,
  canReadDiagnoses: true,
  canReadPlans: true,
  canReadAllergies: true,
  canReadProblems: true,
  canReadMedications: true,
  canReadPrescribing: true,
  canRecordPrescribing: true,
  canRecordLabOrders: true,
  canRequestProcedure: true,
  canRecordNote: true,
  canRecordDiagnosis: true,
  canRecordPlan: true,
  canStartEncounter: true,
  canChangeDisposition: true,
  canCompleteEncounter: true,
};

function completeMedicationOrderForm(): void {
  fireEvent.change(screen.getByLabelText('Dose'), { target: { value: '250 mg' } });
  fireEvent.change(screen.getByLabelText('Route'), { target: { value: 'Oral' } });
  fireEvent.change(screen.getByLabelText('Frequency'), {
    target: { value: 'TID (three times daily)' },
  });
  fireEvent.change(screen.getByLabelText('Duration'), { target: { value: '7' } });
  fireEvent.change(screen.getByLabelText('Duration unit'), { target: { value: 'day' } });
}

beforeEach(() => {
  vi.clearAllMocks();
  (router.post as unknown as Mock).mockReset();
  vi.mocked(sendJson).mockReset().mockResolvedValue({
    savedAt: '2026-11-02T12:00:00+03:00',
  });
  Object.defineProperty(window.navigator, 'onLine', { configurable: true, value: true });
  window.history.replaceState({}, '', '/');
  globalThis.ResizeObserver ??= class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
  Element.prototype.scrollIntoView = vi.fn();
  HTMLElement.prototype.scrollTo = vi.fn();
  window.scrollTo = vi.fn();
});

describe('clinician workstation quick actions', () => {
  it('requires calling a waiting patient before consultation can start', () => {
    const postMock = router.post as unknown as Mock;
    postMock.mockImplementation((_url, _data, options) => {
      const callbacks = options as { onFinish?: () => void };
      callbacks.onFinish?.();
    });

    render(
      <ClinicianShow
        {...baseProps}
        entry={{ ...baseProps.entry, status: 'WAITING' }}
        encounter={{ ...baseProps.encounter, status: 'ARRIVED' }}
        callRooms={[
          { id: 'room-1', name: 'Consultation Room 1', code: 'CONSULT-1' },
          { id: 'room-2', name: 'Consultation Room 2', code: 'CONSULT-2' },
        ]}
        canCall
      />,
    );

    expect(screen.getByText('Waiting to be called')).toBeInTheDocument();
    expect(screen.getByLabelText('Call to')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Start encounter' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Call patient' }));

    expect(router.post).toHaveBeenCalledWith(
      routes.workspaces.ticketCall('entry-1'),
      {
        room: 'room-1',
        return_to: routes.workspaces.clinicianEntry('entry-1'),
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('requires presence confirmation before starting consultation service', () => {
    const postMock = router.post as unknown as Mock;
    postMock.mockImplementation((_url, _data, options) => {
      const callbacks = options as { onFinish?: () => void };
      callbacks.onFinish?.();
    });

    render(
      <ClinicianShow
        {...baseProps}
        entry={{ ...baseProps.entry, status: 'CALLED' }}
        encounter={{ ...baseProps.encounter, status: 'ARRIVED' }}
        canStartService
      />,
    );

    expect(screen.getByRole('status', { name: 'Confirm patient presence' }))
      .toHaveClass('clinician-start-notification');
    expect(screen.queryByRole('button', { name: 'Start encounter' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Confirm presence & start consultation' }));

    expect(router.post).toHaveBeenCalledWith(
      routes.workspaces.ticketStart('entry-1'),
      { return_to: routes.workspaces.clinicianEntry('entry-1') },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('only offers encounter start after consultation service has started', () => {
    render(
      <ClinicianShow
        {...baseProps}
        entry={{ ...baseProps.entry, status: 'IN_SERVICE' }}
        encounter={{ ...baseProps.encounter, status: 'ARRIVED' }}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Start encounter' }));

    expect(router.post).toHaveBeenCalledWith(
      routes.workspaces.receptionStart('encounter-1'),
      { return_to: '/' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('uses the patient chart banner with direct previous and next queue navigation', () => {
    render(<ClinicianShow {...baseProps} />);

    const banner = screen.getByRole('banner', { name: 'Patient header' });

    expect(within(banner).getByRole('heading', { name: 'Amina Juma' })).toBeInTheDocument();
    expect(within(banner).getByText('Active visit')).toBeInTheDocument();
    expect(banner.querySelector(`a[href="${routes.workspaces.clinicianEntry('entry-0')}"]`)).not.toBeNull();
    expect(banner.querySelector(`a[href="${routes.workspaces.clinicianEntry('entry-2')}"]`)).not.toBeNull();
    expect(banner.querySelector(`a[href="${routes.patient.chart('patient-1')}"]`)).not.toBeNull();
  });

  it('uses task-focused Carbon tabs while keeping patient context beside documentation', () => {
    const { container } = render(
      <ClinicianShow
        {...baseProps}
        procedureDefinitions={[{
          definitionId: 'procedure-definition-1',
          code: 'XR-CHEST',
          name: 'Chest X-ray',
          queueConfigured: true,
        }]}
      />,
    );

    const workspaceTabs = screen.getByRole('tablist', { name: 'Clinical encounter workspace' });
    expect(workspaceTabs).toBeInTheDocument();
    const verticalTabs = container.querySelector('.clinician-workspace-navigation .cds--tabs--vertical');
    const documentationPanel = screen.getByRole('tabpanel', { name: 'Document' });
    const draftStatus = screen.getByLabelText('Draft save status');
    const sidebar = screen.getByRole('complementary', { name: 'Patient safety and encounter summary' });
    expect(container.querySelector('.clinical-entry-workspace')).toHaveClass('clinician-workspace');
    expect(container.querySelector('.clinical-entry-workspace__sidebar')).toBe(sidebar);
    expect(container.querySelector('.clinical-entry-workspace-navigation')).toBeInTheDocument();
    expect(container.querySelector('.clinical-entry-tab-content')).toBeInTheDocument();
    expect(verticalTabs).toContainElement(workspaceTabs);
    expect(verticalTabs).toHaveClass('clinician-workspace__vertical-tabs');
    expect(verticalTabs).toHaveClass('cds--layout--size-md');
    expect(documentationPanel).toBeInTheDocument();
    expect(draftStatus.parentElement).toBe(sidebar);
    expect(draftStatus).toHaveClass('clinician-draft-status--card');
    expect(sidebar.firstElementChild).toBe(draftStatus);
    expect(documentationPanel).not.toContainElement(draftStatus);
    expect(verticalTabs).not.toContainElement(draftStatus);
    expect(within(workspaceTabs).getByRole('tab', { name: 'Document' })).toHaveAttribute('aria-selected', 'true');
    expect(within(workspaceTabs).getByRole('tab', { name: 'Order Basket' })).toBeInTheDocument();
    expect(within(workspaceTabs).getByRole('tab', { name: 'Plan & finish' })).toBeInTheDocument();
    expect(within(workspaceTabs).getByRole('tab', { name: 'Visit record' })).toBeInTheDocument();
    expect(container.querySelector('.clinician-workspace')).toHaveClass('clinician-workspace--documentation');
    expect(container.querySelector('#clinician-note')).toHaveClass('clinician-documentation-section--visit-note');
    expect(within(documentationPanel).getByLabelText('Consultation note')).toBeVisible();
    expect(draftStatus).toHaveTextContent('Draft ready to record');
    expect(screen.queryByLabelText('Title (optional)')).not.toBeInTheDocument();
    expect(screen.queryByText('Add note title (optional)')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Search the medication catalog')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Clinical description')).toBeVisible();
    expect(screen.getByLabelText('Code (optional)')).toBeVisible();
    expect(screen.getByLabelText('Code system')).toBeVisible();
    expect(container.querySelectorAll('#clinician-diagnosis details')).toHaveLength(0);
    expect(container.querySelector('#clinician-diagnosis .clinician-diagnosis__fields')?.children).toHaveLength(3);
    fireEvent.click(within(workspaceTabs).getByRole('tab', { name: 'Plan & finish' }));
    expect(screen.getByRole('heading', { name: 'Clinical plan' })).toBeVisible();
    expect(container.querySelector('.clinician-workspace--plan .clinician-documentation-group--additional')).toBeInTheDocument();
    expect(within(workspaceTabs).getByRole('tab', { name: 'Plan & finish' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByLabelText('Clinical plan')).toBeVisible();
    expect(container.querySelectorAll('#clinician-plan details')).toHaveLength(0);
    expect(screen.queryByLabelText('Plan type')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Planned actions (optional)')).not.toBeInTheDocument();
    fireEvent.click(within(workspaceTabs).getByRole('tab', { name: 'Order Basket' }));
    const ordersPanel = screen.getByRole('tabpanel', { name: 'Order Basket' });
    expect(within(ordersPanel).queryByRole('searchbox', { name: 'Search the medication catalog' })).not.toBeInTheDocument();
    fireEvent.click(within(ordersPanel).getByRole('button', { name: 'Add drug orders' }));
    expect(screen.getByRole('dialog', { name: 'Add drug orders' })).toBeInTheDocument();
    expect(screen.getByRole('searchbox', { name: 'Search the medication catalog' })).toBeVisible();
    expect(container.querySelector('.clinician-workspace--orders .clinician-workspace__orders')).toBeInTheDocument();
    expect(within(ordersPanel).getByText('Procedure orders')).toBeVisible();
    fireEvent.click(within(workspaceTabs).getByRole('tab', { name: 'Visit record' }));
    expect(screen.getByText('Encounter record')).toBeVisible();
    expect(container.querySelector('.clinician-workspace--record #clinician-recorded.clinician-recorded--empty')).toBeInTheDocument();
    fireEvent.click(within(workspaceTabs).getByRole('tab', { name: 'Document' }));
    expect(screen.getByLabelText('Draft save status')).toHaveTextContent('Draft ready to record');

    const sidebarHeadings = within(sidebar)
      .getAllByRole('heading', { level: 2 })
      .map((heading) => heading.textContent);
    expect(sidebarHeadings).toEqual([
      'Safety snapshot',
      'Vital signs',
      'Nursing handoff',
    ]);
  });

  it('does not use workflow hashes to change the page scroll position', () => {
    window.history.replaceState({}, '', '/workspaces/clinician/entries/entry-1#clinician-disposition');
    render(<ClinicianShow {...baseProps} />);

    expect(window.location.hash).toBe('#clinician-disposition');
    expect(window.scrollTo).not.toHaveBeenCalled();
    expect(screen.queryByRole('navigation', { name: 'Encounter workflow' })).not.toBeInTheDocument();
  });

  it('uses the diagnosis catalogue and keyboard navigation for the clinical plan', () => {
    const { container } = render(<ClinicianShow {...baseProps} />);

    fireEvent.keyDown(document, { key: 'd', altKey: true });
    expect(container.querySelector('.clinician-workspace')).toHaveClass('clinician-workspace--documentation');
    expect(screen.getByRole('tab', { name: 'Document' })).toHaveAttribute('aria-selected', 'true');
    fireEvent.change(screen.getByRole('combobox', { name: 'Search diagnosis catalogue' }), {
      target: { value: 'ICD-10:B54' },
    });

    expect(screen.getByLabelText('Clinical description')).toHaveValue('Unspecified malaria');
    expect(screen.getByLabelText('Code (optional)')).toHaveValue('B54');
    expect(screen.getByLabelText('Code system')).toHaveValue('ICD-10');
    fireEvent.change(screen.getByRole('combobox', { name: 'Search diagnosis catalogue' }), {
      target: { value: '' },
    });
    expect(screen.getByLabelText('Code (optional)')).toHaveValue('');
    expect(screen.getByLabelText('Code system')).toHaveValue('');
    expect(screen.getByLabelText('Clinical description')).toHaveValue('Unspecified malaria');

    fireEvent.keyDown(document, { key: 'p', altKey: true });
    expect(container.querySelector('.clinician-workspace')).toHaveClass('clinician-workspace--plan');
    expect(screen.getByRole('tab', { name: 'Plan & finish' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByLabelText('Clinical plan')).toBeVisible();
  });

  it('shows diagnosis fields when the catalogue is empty', () => {
    render(<ClinicianShow {...baseProps} diagnosisCatalog={[]} />);

    expect(screen.queryByText('Manual diagnosis details')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Clinical description')).toBeVisible();
    expect(screen.getByLabelText('Code (optional)')).toBeVisible();
    expect(screen.getByLabelText('Code system')).toBeVisible();
  });

  it('shows medication and procedure ordering within the selected workspace tab', () => {
    const { container } = render(<ClinicianShow {...baseProps} />);

    fireEvent.click(screen.getByRole('tab', { name: 'Order Basket' }));

    const orders = screen.getByRole('region', { name: 'Order Basket' });

    expect(container.querySelector('.clinician-workspace')).toHaveClass('clinician-workspace--orders');
    expect(orders.parentElement).toHaveClass('clinician-workspace__tab-content');
    expect(orders.previousElementSibling).toHaveClass('clinician-workspace__form-content');
    expect(within(orders).getByRole('heading', { name: 'Order basket' })).toBeInTheDocument();
    const drugOrders = within(orders).getByRole('region', { name: 'Drug orders' });
    const labOrders = within(orders).getByRole('region', { name: 'Lab orders' });
    expect(drugOrders).toHaveClass('clinician-order-group', 'clinician-order-group--drug');
    expect(labOrders).toHaveClass('clinician-order-group', 'clinician-order-group--lab');
    expect(within(orders).queryByRole('searchbox', { name: 'Search the medication catalog' })).not.toBeInTheDocument();
    const addDrugOrdersButton = within(orders).getByRole('button', { name: 'Add drug orders' });
    expect(addDrugOrdersButton).toHaveClass('cds--btn--tertiary');
    expect(addDrugOrdersButton.querySelector('svg')).toBeInTheDocument();
    const addLabOrdersButton = within(orders).getByRole('button', { name: 'Add lab orders' });
    expect(addLabOrdersButton).toHaveClass('cds--btn--tertiary');
    expect(addLabOrdersButton.querySelector('svg')).toBeInTheDocument();
    const expandDrugOrdersButton = within(orders).getByRole('button', { name: 'View drug orders' });
    expect(expandDrugOrdersButton).toBeDisabled();
    expect(expandDrugOrdersButton).toHaveClass('cds--btn--icon-only');
    expect(within(orders).getByRole('button', { name: 'View lab orders' })).toBeDisabled();
    expect(within(orders).getByText('Procedure orders')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Procedure' })).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog', { name: 'Order basket' })).not.toBeInTheDocument();
  });

  it('stages and submits lab tests and panels from the Order Basket', () => {
    const postMock = router.post as unknown as Mock;
    postMock.mockImplementation((_url, _data, options) => {
      const callbacks = options as { onSuccess?: () => void; onFinish?: () => void };
      callbacks.onSuccess?.();
      callbacks.onFinish?.();
    });

    render(
      <ClinicianShow
        {...baseProps}
        labTestCatalog={[{
          definitionId: 'lab-test-fbc',
          code: 'FBC',
          name: 'Full Blood Count',
          category: 'HAEMATOLOGY',
          specimenType: 'WHOLE_BLOOD',
        }]}
        labPanelCatalog={[{
          panelId: 'lab-panel-fbc',
          code: 'FBC-PANEL',
          name: 'FBC Panel',
          category: 'HAEMATOLOGY',
        }]}
      />,
    );

    fireEvent.click(screen.getByRole('tab', { name: 'Order Basket' }));
    const labOrders = screen.getByRole('region', { name: 'Lab orders' });
    expect(within(labOrders).queryByRole('searchbox', { name: 'Search lab tests and panels' })).not.toBeInTheDocument();
    fireEvent.click(within(labOrders).getByRole('button', { name: 'Add lab orders' }));
    const labSearch = screen.getByRole('searchbox', { name: 'Search lab tests and panels' });
    fireEvent.change(labSearch, { target: { value: 'Full Blood Count' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add to basket' }));
    fireEvent.change(labSearch, { target: { value: 'FBC Panel' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add to basket' }));
    expect(within(labOrders).queryByText('Staged orders')).not.toBeInTheDocument();
    fireEvent.change(within(labOrders).getByLabelText('Priority'), { target: { value: 'URGENT' } });
    fireEvent.change(within(labOrders).getByLabelText('Clinical indication (optional)'), {
      target: { value: 'Fever with suspected infection.' },
    });

    fireEvent.click(within(labOrders).getByRole('button', { name: 'Submit lab order' }));

    expect(router.post).toHaveBeenCalledWith(
      routes.workspaces.encounterLabOrder('encounter-1'),
      expect.objectContaining({
        patient_id: 'patient-1',
        test_definition_ids: ['lab-test-fbc'],
        panel_ids: ['lab-panel-fbc'],
        priority: 'URGENT',
        clinical_indication_text: 'Fever with suspected infection.',
      }),
      expect.any(Object),
    );
    expect(within(labOrders).getByText('Lab order submitted.')).toBeInTheDocument();
    expect(within(labOrders).queryByText('FBC Panel')).not.toBeInTheDocument();
  });

  it('keeps medication ordering available when note recording is not permitted', () => {
    const { container } = render(<ClinicianShow {...baseProps} canRecordNote={false} />);

    fireEvent.click(screen.getByRole('tab', { name: 'Order Basket' }));
    expect(container.querySelector('.clinician-workspace')).toHaveClass('clinician-workspace--orders');
    expect(screen.getByRole('region', { name: 'Order Basket' })).toBeInTheDocument();
    expect(screen.queryByRole('searchbox', { name: 'Search the medication catalog' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Add drug orders' }));
    expect(screen.getByRole('searchbox', { name: 'Search the medication catalog' })).toBeEnabled();
  });

  it('hides the order basket before encounter activation', () => {
    render(
      <ClinicianShow
        {...baseProps}
        encounter={{ ...baseProps.encounter, status: 'ARRIVED' }}
      />,
    );

    expect(screen.queryByRole('region', { name: 'Order Basket' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Visit note' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Diagnosis' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Disposition' })).not.toBeInTheDocument();
  });

  it('shows medication actions under each result and prevents signing incomplete orders', () => {
    render(<ClinicianShow {...baseProps} />);

    fireEvent.click(screen.getByRole('tab', { name: 'Order Basket' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add drug orders' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search the medication catalog' }), {
      target: { value: 'Amoxicillin' },
    });
    const drugPicker = screen.getByRole('dialog', { name: 'Add drug orders' });
    expect(drugPicker).toHaveStyle({ width: 'min(30rem, calc(100vw - 2rem))' });
    expect(drugPicker.querySelector('.afy-dock__content')).toHaveClass('clinician-order-picker--drug-search');
    expect(within(drugPicker).queryByText('MED-AMOX-250')).not.toBeInTheDocument();
    expect(within(drugPicker).getByText('Unit: capsule')).toBeInTheDocument();
    const addToBasketButton = within(drugPicker).getByRole('button', { name: 'Add to basket' });
    expect(addToBasketButton).toHaveClass('cds--btn--tertiary');
    expect(addToBasketButton.querySelector('svg')).toBeInTheDocument();
    const medicationOrderFormButton = within(drugPicker).getByRole('button', { name: 'Order form' });
    expect(medicationOrderFormButton).toHaveClass('cds--btn--ghost');
    expect(medicationOrderFormButton.querySelector('svg')).toBeInTheDocument();
    const medicationResult = drugPicker.querySelector('.clinician-medication-search-result');
    expect(medicationResult?.firstElementChild).toHaveClass('clinician-medication-search-result__item');
    expect(medicationResult?.lastElementChild).toHaveClass('clinician-medication-search-result__actions');
    fireEvent.click(screen.getByRole('button', { name: 'Add to basket' }));

    expect(screen.getByText('Incomplete')).toBeInTheDocument();
    expect(screen.getByText('Code: MED-AMOX-250 · Unit: capsule')).toBeInTheDocument();
    const stagedOrderFormButton = screen.getAllByRole('button', { name: 'Order form' })
      .find((button) => button.closest('.clinician-medication-basket__list'));
    expect(stagedOrderFormButton?.querySelector('svg')).toBeInTheDocument();
    const removeMedicationButton = screen.getAllByRole('button', { name: 'Remove' })
      .find((button) => button.closest('.clinician-medication-basket__list'));
    expect(removeMedicationButton).toHaveClass('cds--btn--danger--ghost');
    expect(removeMedicationButton?.querySelector('svg')).toBeInTheDocument();
    expect(screen.queryByText('Staged orders')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Sign prescriptions' })).toBeDisabled();
    expect(screen.queryByLabelText('Dose')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Hide drug orders' }));
    expect(screen.queryByRole('button', { name: 'Sign prescriptions' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'View drug orders' }));
    expect(screen.getByRole('button', { name: 'Sign prescriptions' })).toBeInTheDocument();

    fireEvent.click(within(drugPicker).getByRole('button', { name: 'Order form' }));
    const medicationOrderForm = screen.getByRole('dialog', { name: 'Medication order form' });
    expect(medicationOrderForm).toHaveStyle({ width: 'min(30rem, calc(100vw - 2rem))' });
    completeMedicationOrderForm();

    expect(screen.getByText('Complete')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Sign prescriptions' })).toBeEnabled();
  });

  it('opens a medication order form directly from a search result', () => {
    render(<ClinicianShow {...baseProps} />);

    fireEvent.click(screen.getByRole('tab', { name: 'Order Basket' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add drug orders' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search the medication catalog' }), {
      target: { value: 'Amoxicillin' },
    });

    const drugPicker = screen.getByRole('dialog', { name: 'Add drug orders' });
    fireEvent.click(within(drugPicker).getByRole('button', { name: 'Order form' }));

    expect(screen.getByRole('dialog', { name: 'Medication order form' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Drug orders (1)' })).toBeInTheDocument();
    expect(screen.getByLabelText('Dose')).toBeInTheDocument();
  });

  it('records custom medication directions and a positive quantity in the catalog unit', async () => {
    const postMock = router.post as unknown as Mock;
    postMock.mockImplementation((_url, _data, options) => {
      (options as { onSuccess?: () => void }).onSuccess?.();
    });

    render(<ClinicianShow {...baseProps} />);
    fireEvent.click(screen.getByRole('tab', { name: 'Order Basket' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add drug orders' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search the medication catalog' }), {
      target: { value: 'Amoxicillin' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Order form' }));
    fireEvent.change(screen.getByLabelText('Dose'), { target: { value: '1 capsule' } });
    fireEvent.change(screen.getByLabelText('Route'), { target: { value: OTHER_MEDICATION_OPTION } });
    fireEvent.change(screen.getByLabelText('Custom route'), { target: { value: 'Buccal' } });
    fireEvent.change(screen.getByLabelText('Frequency'), { target: { value: OTHER_MEDICATION_OPTION } });
    fireEvent.change(screen.getByLabelText('Custom frequency'), {
      target: { value: 'Every 6 hours as needed' },
    });
    fireEvent.change(screen.getByLabelText('Duration unit'), {
      target: { value: CUSTOM_MEDICATION_DURATION },
    });
    fireEvent.change(screen.getByLabelText('Custom duration'), { target: { value: 'until review' } });
    fireEvent.change(screen.getByLabelText('Quantity to dispense'), { target: { value: '0' } });
    expect(screen.getByRole('button', { name: 'Sign prescriptions' })).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Quantity to dispense'), { target: { value: '14' } });
    fireEvent.change(screen.getByLabelText('Indication'), { target: { value: 'Pain' } });
    fireEvent.change(screen.getByLabelText('Patient instructions'), {
      target: { value: 'Take with food.' },
    });
    expect(screen.getByRole('button', { name: 'Sign prescriptions' })).toBeEnabled();
    fireEvent.click(screen.getByRole('button', { name: 'Sign prescriptions' }));

    await waitFor(() => {
      expect(postMock).toHaveBeenCalledTimes(1);
    });
    expect(postMock.mock.calls[0]?.[1]).toMatchObject({
      dose: '1 capsule',
      route: 'Buccal',
      frequency: 'Every 6 hours as needed',
      duration: 'until review',
      quantity_value: '14',
      quantity_unit: 'capsule',
      indication: 'Pain',
      dosing_instruction: 'Take with food.',
    });
  });

  it('hides unauthorized active encounter rail actions', () => {
    render(
      <ClinicianShow
        {...baseProps}
        canRecordNote={false}
        canRecordDiagnosis={false}
        canRecordPlan={false}
        canRequestProcedure={false}
        canReadPrescribing={false}
        canRecordPrescribing={false}
        canChangeDisposition={false}
        canCompleteEncounter={false}
      />,
    );

    expect(screen.queryByRole('complementary', { name: 'Quick actions' })).not.toBeInTheDocument();
    expect(screen.queryByRole('complementary', { name: 'Orders and prescriptions' })).not.toBeInTheDocument();
  });


  it('shows a permission-aware safety snapshot for allergies, problems and medications', () => {
    render(
      <ClinicianShow
        {...baseProps}
        allergies={[
          {
            allergyId: 'allergy-1',
            substanceName: 'Penicillin',
            reactionDescription: 'Hives and wheeze',
            severity: 'SEVERE',
            verificationStatus: 'CONFIRMED',
            clinicalStatus: 'ACTIVE',
          },
        ]}
        problems={[
          {
            problemId: 'problem-1',
            description: 'Essential (primary) hypertension',
            code: 'I10',
            codeSystem: 'ICD-10',
            status: 'ACTIVE',
          },
        ]}
        medications={[
          {
            medicationId: 'prescription-1',
            medicationName: 'Amoxicillin 250 mg',
            dose: '250 mg',
            route: 'ORAL',
            frequency: 'Three times daily',
            status: 'ACTIVE',
            occurredAt: '2026-11-02T09:00:00+03:00',
          },
        ]}
      />,
    );

    expect(screen.getByText(/Penicillin/)).toBeInTheDocument();
    expect(screen.getByText('Essential (primary) hypertension')).toBeInTheDocument();
    expect(screen.getByText(/Amoxicillin 250 mg/)).toBeInTheDocument();
    expect(screen.getAllByText('View')).toHaveLength(2);
  });

  it('keeps nursing notes and vital signs visible beside the documentation workspace', () => {
    render(
      <ClinicianShow
        {...baseProps}
        vitals={[{
          temperature: 38.2,
          heartRate: 104,
          respiratoryRate: 22,
          systolicBp: 118,
          diastolicBp: 72,
          oxygenSaturation: 96,
          weight: null,
          height: null,
          painScore: 6,
          occurredAt: '2026-11-02T09:00:00+03:00',
          authoredContext: 'NURSING',
        }]}
        notes={[{
          clinicalNoteId: 'nursing-note-1',
          authoredContext: 'NURSING',
          noteType: 'NURSING_NOTE',
          title: 'Nursing observation',
          body: 'Fever recorded; patient reports persistent headache.',
          status: 'DRAFT',
          occurredAt: '2026-11-02T09:05:00+03:00',
          recordedAt: '2026-11-02T09:05:00+03:00',
          signedAt: null,
          amendmentCount: 0,
        }]}
      />,
    );

    const sidebar = screen.getByRole('complementary', { name: 'Patient safety and encounter summary' });
    expect(within(sidebar).getByText('Nursing notes')).toBeInTheDocument();
    expect(within(sidebar).getByText('Fever recorded; patient reports persistent headache.')).toBeInTheDocument();
    expect(within(sidebar).getByText('38.2')).toBeInTheDocument();
    expect(screen.getByLabelText('Consultation note')).toBeVisible();
  });

  it('confirms signing a saved draft note and sends the authorized action', () => {
    const postMock = router.post as unknown as Mock;
    postMock.mockImplementation((_url, _data, options) => {
      const callbacks = options as { onSuccess?: () => void; onFinish?: () => void };
      callbacks.onSuccess?.();
      callbacks.onFinish?.();
    });
    render(
      <ClinicianShow
        {...baseProps}
        notes={[{
          clinicalNoteId: 'note-draft',
          noteType: 'CONSULTATION',
          title: 'Initial consultation',
          body: 'Patient reports a two-day fever.',
          status: 'DRAFT',
          occurredAt: '2026-11-02T09:00:00+03:00',
          recordedAt: '2026-11-02T09:00:00+03:00',
          signedAt: null,
          amendmentCount: 0,
        }]}
      />,
    );

    fireEvent.click(screen.getByRole('tab', { name: /Visit record/ }));
    fireEvent.click(screen.getByText('Recorded this visit'));
    fireEvent.click(screen.getByRole('button', { name: 'Sign note' }));

    const dialog = screen.getByRole('dialog', { name: 'Sign this clinical note?' });
    expect(within(dialog).getByText(/The signed note cannot be edited/)).toBeInTheDocument();
    expect(router.post).not.toHaveBeenCalled();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirm signature' }));

    expect(router.post).toHaveBeenCalledWith(
      routes.workspaces.clinicalNoteSign('note-draft'),
      {},
      expect.any(Object),
    );
  });

  it('appends an amendment to a saved signed note without replacing the original', () => {
    const postMock = router.post as unknown as Mock;
    postMock.mockImplementation((_url, _data, options) => {
      const callbacks = options as { onSuccess?: () => void; onFinish?: () => void };
      callbacks.onSuccess?.();
      callbacks.onFinish?.();
    });
    render(
      <ClinicianShow
        {...baseProps}
        notes={[{
          clinicalNoteId: 'note-signed',
          noteType: 'CONSULTATION',
          title: 'Signed consultation',
          body: 'Original signed note content.',
          status: 'SIGNED',
          occurredAt: '2026-11-02T09:00:00+03:00',
          recordedAt: '2026-11-02T09:00:00+03:00',
          signedAt: '2026-11-02T09:15:00+03:00',
          amendmentCount: 0,
        }]}
      />,
    );

    fireEvent.click(screen.getByRole('tab', { name: /Visit record/ }));
    fireEvent.click(screen.getByText('Recorded this visit'));
    fireEvent.click(screen.getByRole('button', { name: 'Amend note' }));

    const panel = screen.getByRole('dialog', { name: 'Amend signed clinical note' });
    expect(within(panel).getByText('Original signed note content.')).toBeInTheDocument();
    fireEvent.change(within(panel).getByLabelText('Amendment type'), { target: { value: 'CORRECTION' } });
    fireEvent.change(within(panel).getByLabelText('Addendum or correction'), {
      target: { value: 'Blood pressure corrected to 128/82.' },
    });
    fireEvent.change(within(panel).getByLabelText('Reason for amendment'), {
      target: { value: 'Transcription error.' },
    });
    fireEvent.click(within(panel).getByRole('button', { name: 'Close Amend signed clinical note' }));
    const discardDialog = screen.getByRole('dialog', { name: 'Discard this amendment?' });
    expect(within(panel).getByLabelText('Addendum or correction')).toHaveValue('Blood pressure corrected to 128/82.');
    fireEvent.click(within(discardDialog).getByRole('button', { name: 'Cancel' }));
    expect(within(panel).getByLabelText('Reason for amendment')).toHaveValue('Transcription error.');
    fireEvent.click(within(panel).getByRole('button', { name: 'Append amendment' }));

    expect(router.post).toHaveBeenCalledWith(
      routes.workspaces.clinicalNoteAmend('note-signed'),
      {
        amendment_type: 'CORRECTION',
        body: 'Blood pressure corrected to 128/82.',
        reason: 'Transcription error.',
      },
      expect.any(Object),
    );
  });

  it('hides saved-note actions the clinician is not authorized to perform', () => {
    render(
      <ClinicianShow
        {...baseProps}
        canSignNotes={false}
        canAmendNotes={false}
        notes={[
          {
            clinicalNoteId: 'note-draft',
            noteType: 'CONSULTATION',
            title: 'Draft consultation',
            body: 'Draft note content.',
            status: 'DRAFT',
            occurredAt: '2026-11-02T09:00:00+03:00',
            recordedAt: '2026-11-02T09:00:00+03:00',
            signedAt: null,
            amendmentCount: 0,
          },
          {
            clinicalNoteId: 'note-signed',
            noteType: 'CONSULTATION',
            title: 'Signed consultation',
            body: 'Signed note content.',
            status: 'SIGNED',
            occurredAt: '2026-11-02T09:00:00+03:00',
            recordedAt: '2026-11-02T09:00:00+03:00',
            signedAt: '2026-11-02T09:15:00+03:00',
            amendmentCount: 0,
          },
        ]}
      />,
    );

    fireEvent.click(screen.getByRole('tab', { name: /Visit record/ }));
    fireEvent.click(screen.getByText('Recorded this visit'));
    expect(screen.queryByRole('button', { name: 'Sign note' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Amend note' })).not.toBeInTheDocument();
  });

  it('shows explicit empty and unavailable safety states', () => {
    render(<ClinicianShow {...baseProps} />);
    expect(screen.getByText('No recorded active allergies')).toBeInTheDocument();
    expect(screen.queryByText(/verify before prescribing/i)).not.toBeInTheDocument();
    expect(screen.getByText('No active problems recorded')).toBeInTheDocument();
    expect(screen.getByText('No medications ordered this visit')).toBeInTheDocument();

    render(
      <ClinicianShow
        {...baseProps}
        allergies={[
          {
            allergyId: 'allergy-hidden',
            substanceName: 'Restricted allergy',
            reactionDescription: null,
            severity: 'SEVERE',
            verificationStatus: 'CONFIRMED',
            clinicalStatus: 'ACTIVE',
          },
        ]}
        canReadAllergies={false}
        canReadProblems={false}
        canReadMedications={false}
      />,
    );
    expect(screen.getByText('Unavailable. Verify before prescribing.')).toBeInTheDocument();
    expect(screen.queryByText(/Restricted allergy/)).not.toBeInTheDocument();
    expect(screen.getAllByText('Not available')).toHaveLength(2);
  });

  it('requires confirmation before completing the visit', () => {
    const { container } = render(<ClinicianShow {...baseProps} />);

    fireEvent.change(screen.getByLabelText('Disposition'), { target: { value: 'FOLLOW_UP' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save disposition' }));

    const disposition = container.querySelector('#clinician-disposition') as HTMLElement;
    const completeButton = within(disposition).getByRole('button', { name: 'Complete visit' });
    expect(completeButton).toBeEnabled();
    fireEvent.click(completeButton);

    const dialog = screen.getByRole('dialog', { name: 'Complete this visit?' });
    expect(router.post).not.toHaveBeenCalled();
    expect(within(dialog).getByText(/0 note\(s\), 0 diagnosis\(es\), and 0 plan\(s\)/)).toBeInTheDocument();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Complete visit' }));

    expect(router.post).toHaveBeenCalledTimes(1);
    expect(router.post).toHaveBeenCalledWith(
      routes.workspaces.receptionComplete('encounter-1'),
      { return_to: routes.workspaces.clinician },
      expect.any(Object),
    );
  });

  it('summarizes documentation recorded during the encounter in a collapsed disclosure', () => {
    render(
      <ClinicianShow
        {...baseProps}
        procedureDefinitions={[{
          definitionId: 'procedure-definition-1',
          code: 'XR-CHEST',
          name: 'Chest X-ray',
          queueConfigured: true,
        }]}
        procedures={[{
          procedureId: 'procedure-1',
          procedureNumber: 'PROC-001',
          definitionId: 'procedure-definition-1',
          status: 'AUTHORIZED',
          authorizationBasis: null,
          authorizationProtocolReference: null,
          indication: 'Persistent fever and cough.',
        }]}
        prescribingDecisions={[{
          prescribingDecisionId: 'decision-1',
          medicationName: 'Amoxicillin 250 mg',
          dose: '250 mg',
          route: 'ORAL',
          frequency: 'Three times daily',
          duration: '7 days',
          quantity: '21 capsules',
          quantityValue: '21',
          quantityUnit: 'capsules',
          dosingInstruction: null,
          status: 'ACTIVE',
          occurredAt: '2026-11-02T09:15:00+03:00',
        }]}
        notes={[
          {
            clinicalNoteId: 'note-1',
            noteType: 'CONSULTATION',
            title: 'Initial consultation',
            body: 'Patient reports a two-day fever.',
            status: 'DRAFT',
            occurredAt: '2026-11-02T09:00:00+03:00',
            recordedAt: '2026-11-02T09:00:00+03:00',
            signedAt: null,
            amendmentCount: 0,
          },
        ]}
        diagnoses={[
          {
            diagnosisId: 'diagnosis-1',
            description: 'Acute febrile illness',
            code: 'A99',
            codeSystem: 'LOCAL',
            clinicalStatus: 'WORKING',
            occurredAt: '2026-11-02T09:05:00+03:00',
            recordedAt: '2026-11-02T09:05:00+03:00',
          },
        ]}
        plans={[
          {
            planId: 'plan-1',
            planType: 'FOLLOW_UP',
            clinicalIntent: 'Review symptoms in two days.',
            status: 'ACTIVE',
            followUp: 'Return if symptoms worsen.',
            occurredAt: '2026-11-02T09:10:00+03:00',
          },
        ]}
      />,
    );

    fireEvent.click(screen.getByRole('tab', { name: /Visit record/ }));
    const summary = screen.getByText('Recorded this visit');
    const details = summary.closest('details');
    expect(details).not.toBeNull();
    expect(details).not.toHaveAttribute('open');
    expect(within(details as HTMLElement).getByText('5')).toBeInTheDocument();
    expect(screen.getByText('Initial consultation')).toBeInTheDocument();
    expect(screen.getByText('Acute febrile illness')).toBeInTheDocument();
    expect(screen.getByText('Review symptoms in two days.')).toBeInTheDocument();
    fireEvent.click(summary);
    expect(within(details as HTMLElement).getByText('Medication orders')).toBeInTheDocument();
    expect(within(details as HTMLElement).getByText('Procedure orders')).toBeInTheDocument();
    expect(within(details as HTMLElement).getByText('Amoxicillin 250 mg')).toBeInTheDocument();
    expect(within(details as HTMLElement).getByText('Chest X-ray')).toBeInTheDocument();
    expect(within(details as HTMLElement).getByRole('link', { name: 'View laboratory results' }))
      .toHaveAttribute('href', routes.patient.chart('patient-1', 'lab-results'));
  });

  it('restores a server draft into the right section', () => {
    render(
      <ClinicianShow
        {...baseProps}
        drafts={{
          note: {
            values: {
              note_type: 'CONSULTATION',
              title: 'Resumed consultation',
              body: 'Restored private draft body.',
            },
            savedAt: '2026-11-02T10:00:00+03:00',
            expiresAt: '2026-11-09T10:00:00+03:00',
          },
          diagnosis: null,
          plan: null,
        }}
      />,
    );

    expect(screen.queryByLabelText('Title (optional)')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Consultation note')).toHaveValue(
      'Resumed consultation\n\nRestored private draft body.',
    );
    expect(screen.getAllByText('Restored draft')).toHaveLength(1);
    expect(screen.getByText(/Draft saved/)).toBeInTheDocument();
  });

  it('discards a note draft only after confirmation and clears the note fields', async () => {
    const { container } = render(
      <ClinicianShow
        {...baseProps}
        drafts={{
          note: {
            values: { title: 'Resumed consultation', body: 'Draft body.' },
            savedAt: '2026-11-02T10:00:00+03:00',
            expiresAt: '2026-11-09T10:00:00+03:00',
          },
          diagnosis: null,
          plan: null,
        }}
      />,
    );

    const noteSection = within(container.querySelector('#clinician-note') as HTMLElement);
    fireEvent.click(noteSection.getByRole('button', { name: 'Discard draft' }));
    const dialog = screen.getByRole('dialog', { name: 'Discard this documentation draft?' });
    expect(sendJson).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Consultation note')).toHaveValue(
      'Resumed consultation\n\nDraft body.',
    );

    fireEvent.click(within(dialog).getByRole('button', { name: 'Discard draft' }));

    await waitFor(() => {
      expect(sendJson).toHaveBeenCalledWith(
        routes.workspaces.encounterDocumentationDraftSection('encounter-1', 'NOTE'),
        'DELETE',
      );
    });
    expect(screen.getByLabelText('Consultation note')).toHaveValue('');
    expect(screen.queryByText('Restored draft')).not.toBeInTheDocument();
    expect(noteSection.queryByRole('button', { name: 'Discard draft' })).not.toBeInTheDocument();
  });

  it('keeps draft content and reports an error when server discard fails', async () => {
    vi.mocked(sendJson).mockRejectedValueOnce(new Error('Network unavailable'));
    const { container } = render(
      <ClinicianShow
        {...baseProps}
        drafts={{
          note: {
            values: { title: 'Resumed consultation', body: 'Draft body.' },
            savedAt: '2026-11-02T10:00:00+03:00',
            expiresAt: '2026-11-09T10:00:00+03:00',
          },
          diagnosis: null,
          plan: null,
        }}
      />,
    );

    const noteSection = within(container.querySelector('#clinician-note') as HTMLElement);
    fireEvent.click(noteSection.getByRole('button', { name: 'Discard draft' }));
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Discard this documentation draft?' }))
      .getByRole('button', { name: 'Discard draft' }));

    expect(await screen.findByText(/The draft could not be discarded/)).toBeInTheDocument();
    expect(screen.getByLabelText('Consultation note')).toHaveValue(
      'Resumed consultation\n\nDraft body.',
    );
    expect(screen.getByText('Restored draft')).toBeInTheDocument();
  });

  it('discards diagnosis drafts without removing recorded diagnoses', async () => {
    const { container } = render(
      <ClinicianShow
        {...baseProps}
        drafts={{
          note: null,
          diagnosis: {
            values: {
              description: 'Unspecified malaria',
              code: 'B54',
              code_system: 'ICD-10',
              clinical_status: 'WORKING',
            },
            savedAt: '2026-11-02T10:00:00+03:00',
            expiresAt: '2026-11-09T10:00:00+03:00',
          },
          plan: null,
        }}
        diagnoses={[{
          diagnosisId: 'diagnosis-recorded',
          description: 'Recorded diagnosis remains',
          code: null,
          codeSystem: null,
          clinicalStatus: 'CONFIRMED',
          occurredAt: '2026-11-02T09:00:00+03:00',
          recordedAt: '2026-11-02T09:00:00+03:00',
        }]}
      />,
    );

    fireEvent.click(within(container.querySelector('#clinician-diagnosis') as HTMLElement)
      .getByRole('button', { name: 'Discard draft' }));
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Discard this documentation draft?' }))
      .getByRole('button', { name: 'Discard draft' }));

    await waitFor(() => {
      expect(sendJson).toHaveBeenCalledWith(
        routes.workspaces.encounterDocumentationDraftSection('encounter-1', 'DIAGNOSIS'),
        'DELETE',
      );
    });
    expect(screen.getByLabelText('Clinical description')).toHaveValue('');
    expect(screen.getByLabelText('Code (optional)')).toHaveValue('');
    expect(screen.getByText('Recorded diagnosis remains')).toBeInTheDocument();
  });

  it('discards plan drafts and resets the plan form', async () => {
    const { container } = render(
      <ClinicianShow
        {...baseProps}
        drafts={{
          note: null,
          diagnosis: null,
          plan: {
            values: {
              plan_type: 'FOLLOW_UP',
              clinical_intent: 'Review symptoms in two days.',
              goals: 'Symptoms improve.',
              planned_actions: 'Review response.',
              instructions: 'Return if worse.',
              follow_up: 'In two days.',
            },
            savedAt: '2026-11-02T10:00:00+03:00',
            expiresAt: '2026-11-09T10:00:00+03:00',
          },
        }}
      />,
    );

    fireEvent.click(within(container.querySelector('#clinician-plan') as HTMLElement)
      .getByRole('button', { name: 'Discard draft' }));
    expect(screen.getByLabelText('Clinical plan')).toHaveValue(
      'Review symptoms in two days.\n\nGoals: Symptoms improve.\n\nPlanned actions: Review response.\n\nInstructions: Return if worse.\n\nFollow-up: In two days.',
    );
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Discard this documentation draft?' }))
      .getByRole('button', { name: 'Discard draft' }));

    await waitFor(() => {
      expect(sendJson).toHaveBeenCalledWith(
        routes.workspaces.encounterDocumentationDraftSection('encounter-1', 'PLAN'),
        'DELETE',
      );
    });
    expect(screen.getByLabelText('Clinical plan')).toHaveValue('');
    expect(screen.queryByLabelText('Plan type')).not.toBeInTheDocument();
  });

  it('requires a connection before a server draft can be discarded', () => {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, value: false });
    const { container } = render(
      <ClinicianShow
        {...baseProps}
        drafts={{
          note: {
            values: { title: 'Resumed consultation', body: 'Draft body.' },
            savedAt: '2026-11-02T10:00:00+03:00',
            expiresAt: '2026-11-09T10:00:00+03:00',
          },
          diagnosis: null,
          plan: null,
        }}
      />,
    );

    expect(within(container.querySelector('#clinician-note') as HTMLElement)
      .getByRole('button', { name: 'Discard draft' })).toBeDisabled();
    expect(screen.getByText('Reconnect to discard this draft.')).toBeInTheDocument();
    expect(sendJson).not.toHaveBeenCalled();
  });

  it('retries an unsaved draft automatically when the connection returns', async () => {
    vi.mocked(sendJson)
      .mockRejectedValueOnce(new Error('Network unavailable'))
      .mockResolvedValueOnce({ savedAt: '2026-11-02T12:01:00+03:00' });

    render(<ClinicianShow {...baseProps} />);

    fireEvent.change(screen.getByLabelText('Consultation note'), {
      target: { value: 'Offline clinical note.' },
    });

    await waitFor(() => {
      expect(sendJson).toHaveBeenCalledTimes(1);
    }, { timeout: 3000 });
    expect(screen.getByText('Draft not saved')).toBeInTheDocument();

    fireEvent(window, new Event('online'));

    await waitFor(() => {
      expect(sendJson).toHaveBeenCalledTimes(2);
    }, { timeout: 3000 });
    expect(sendJson).toHaveBeenLastCalledWith(
      routes.workspaces.encounterDocumentationDraft('encounter-1'),
      'POST',
      {
        section: 'NOTE',
        patient_id: 'patient-1',
        values: {
          note_type: 'CONSULTATION',
          body: 'Offline clinical note.',
        },
      },
    );
    expect(await screen.findByText(/Draft saved/)).toBeInTheDocument();
  });

  it('uses a fresh medication idempotency key after changing a failed order', async () => {
    const postMock = router.post as unknown as Mock;
    let call = 0;
    postMock.mockImplementation((_url, _data, options) => {
      call += 1;
      const callbacks = options as {
        onSuccess?: () => void;
        onError?: (errors: Record<string, string>) => void;
        onFinish?: () => void;
      };
      if (call === 1) {
        callbacks.onError?.({ medication_name: 'The result was not confirmed.' });
      } else {
        callbacks.onSuccess?.();
      }
      callbacks.onFinish?.();
    });

    render(<ClinicianShow {...baseProps} />);
    fireEvent.click(screen.getByRole('tab', { name: 'Order Basket' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add drug orders' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search the medication catalog' }), {
      target: { value: 'Amoxicillin' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Order form' }));
    completeMedicationOrderForm();
    fireEvent.click(screen.getByRole('button', { name: 'Sign prescriptions' }));

    await waitFor(() => {
      expect(screen.getByText(/0 of 1 orders recorded/)).toBeInTheDocument();
    });
    const failedOrderKey = postMock.mock.calls[0]?.[1].idempotency_key;

    fireEvent.change(screen.getByLabelText('Dose'), { target: { value: '500 mg' } });
    fireEvent.click(screen.getByRole('button', { name: 'Sign prescriptions' }));

    await waitFor(() => {
      expect(screen.queryByLabelText('Dose')).not.toBeInTheDocument();
    });
    expect(postMock.mock.calls[1]?.[1].idempotency_key).not.toBe(failedOrderKey);
  });

  it('locks an order with an unknown outcome and retries it unchanged', async () => {
    const postMock = router.post as unknown as Mock;
    let call = 0;
    postMock.mockImplementation((_url, _data, options) => {
      call += 1;
      const callbacks = options as { onSuccess?: () => void; onFinish?: () => void };
      if (call === 1) {
        callbacks.onFinish?.();
      } else {
        callbacks.onSuccess?.();
        callbacks.onFinish?.();
      }
    });

    render(<ClinicianShow {...baseProps} />);
    fireEvent.click(screen.getByRole('tab', { name: 'Order Basket' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add drug orders' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search the medication catalog' }), {
      target: { value: 'Amoxicillin' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Order form' }));
    completeMedicationOrderForm();
    fireEvent.click(screen.getByRole('button', { name: 'Sign prescriptions' }));

    await waitFor(() => {
      expect(screen.getByText(/result was not confirmed/)).toBeInTheDocument();
    });
    const firstAttemptKey = postMock.mock.calls[0]?.[1].idempotency_key;
    expect(screen.getByLabelText('Dose')).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Sign prescriptions' }));

    await waitFor(() => {
      expect(screen.queryByLabelText('Dose')).not.toBeInTheDocument();
    });
    expect(postMock.mock.calls[1]?.[1].idempotency_key).toBe(firstAttemptKey);
  });

  it('removes signed orders from the basket and retries only the remaining orders', async () => {
    const catalog = [
      baseProps.medicationCatalog[0]!,
      {
        id: 'med-2',
        code: 'MED-PARA-500',
        name: 'Paracetamol 500 mg',
        unitOfMeasure: 'tablet',
        category: 'ANALGESIC',
      },
    ];
    const postMock = router.post as unknown as Mock;
    let call = 0;
    postMock.mockImplementation((_url, _data, options) => {
      call += 1;
      const callbacks = options as {
        onSuccess?: () => void;
        onError?: (errors: Record<string, string>) => void;
      };
      if (call === 1) {
        callbacks.onSuccess?.();
      } else {
        callbacks.onError?.({ medication_name: 'Dispensing stock unavailable.' });
      }
    });

    render(<ClinicianShow {...baseProps} medicationCatalog={catalog} />);
    fireEvent.click(screen.getByRole('tab', { name: 'Order Basket' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add drug orders' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search the medication catalog' }), {
      target: { value: 'Amoxicillin' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add to basket' }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search the medication catalog' }), {
      target: { value: 'Paracetamol' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add to basket' }));

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search the medication catalog' }), {
      target: { value: 'Amoxicillin' },
    });
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Add drug orders' }))
      .getByRole('button', { name: 'Order form' }));
    completeMedicationOrderForm();
    fireEvent.click(screen.getByRole('button', { name: 'Back to drug search' }));

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search the medication catalog' }), {
      target: { value: 'Paracetamol' },
    });
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Add drug orders' }))
      .getByRole('button', { name: 'Order form' }));
    completeMedicationOrderForm();

    fireEvent.click(screen.getByRole('button', { name: 'Sign prescriptions' }));

    await waitFor(() => {
      expect(screen.getByText(/1 of 2 orders recorded/)).toBeInTheDocument();
    });
    const failedOrderKey = postMock.mock.calls[1]?.[1].idempotency_key;
    expect(screen.getByRole('heading', { name: 'Drug orders (1)' })).toBeInTheDocument();
    expect(screen.getAllByText('Paracetamol 500 mg').length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: 'Sign prescriptions' })).toBeEnabled();

    call = 0;
    postMock.mockImplementation((_url, _data, options) => {
      call += 1;
      (options as { onSuccess?: () => void }).onSuccess?.();
    });
    fireEvent.click(screen.getByRole('button', { name: 'Sign prescriptions' }));

    await waitFor(() => {
      expect(screen.queryByLabelText('Dose')).not.toBeInTheDocument();
    });
    expect(postMock).toHaveBeenCalledTimes(3);
    expect(postMock.mock.calls[2]?.[1]).toMatchObject({ medication_name: 'Paracetamol 500 mg' });
    expect(postMock.mock.calls[2]?.[1].idempotency_key).toBe(failedOrderKey);
    expect(postMock.mock.calls[0]?.[1].idempotency_key).not.toBe(failedOrderKey);
  });
});
