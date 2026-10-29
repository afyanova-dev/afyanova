import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import PharmacyIndex from '@/pages/Pharmacy/Index';
import { PharmacyBoard } from '@/features/pharmacy/components/PharmacyBoard';
import type { PharmacyBoardProps } from '@/features/pharmacy/types/pharmacy';

const state = vi.hoisted(() => ({
  url: '/workspaces/pharmacy',
  getJson: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Link: ({
    children,
    href,
    onClick,
  }: {
    children: React.ReactNode;
    href: string;
    onClick?: (event: React.MouseEvent<HTMLAnchorElement>) => void;
  }) => <a href={href} onClick={onClick}>{children}</a>,
  router: { visit: vi.fn(), get: vi.fn(), post: vi.fn() },
  usePage: () => ({ url: state.url, props: {} }),
}));

vi.mock('@/lib/http', () => ({
  getJson: state.getJson,
}));

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const baseProps: PharmacyBoardProps = {
  facilityId: 'facility-1',
  canTranscribe: true,
  canCreatePharmacyPatient: true,
  externalIntake: false,
  canManageOrders: true,
  canDispense: true,
  canReverse: true,
  canRecordPickup: true,
  prescriptions: [{
    prescriptionId: 'rx-1',
    prescriptionNumber: 'RX-0001',
    patientId: 'patient-1',
    patientDisplayName: 'Amina Juma',
    patientNumber: 'PAT-00000001',
    patientAge: 45,
    patientSex: 'FEMALE',
    source: 'PAPER_TRANSCRIPTION',
    status: 'ACTIVE',
    medicationName: 'Amoxicillin',
    medicationCode: 'AMOX-500',
    inventoryItemId: 'item-amox',
    prescriberName: 'Dr. Asha',
    occurredAt: '2026-10-01',
    recordedAt: '2026-10-01T10:00:00Z',
    route: 'ORAL',
    urgency: null,
    dose: '500 mg',
    frequency: 'Once daily',
    duration: '5 days',
    prescribedQuantity: '10 tablets',
    dosingInstruction: 'Take by mouth',
    transcriptionNote: null,
    externalIntakeId: null,
    lastDispenserName: null,
  }],
  prescriptionStatus: null,
  orders: [{
    pharmacyOrderId: 'order-1',
    orderNumber: 'ORD-0001',
    prescriptionId: 'rx-1',
    patientId: 'patient-1',
    patientDisplayName: 'Amina Juma',
    patientNumber: 'PAT-00000001',
    patientAge: 45,
    patientSex: 'FEMALE',
    medicationName: 'Amoxicillin',
    status: 'VALIDATED',
    priority: 'ROUTINE',
    dispensingCount: 0,
    occurredAt: '2026-10-01T10:00:00Z',
    dispensings: [],
    externalIntakeId: null,
  }],
  orderStatus: null,
  selectedOrderId: null,
  selectedOrderNumber: null,
  selectedOrderStatus: null,
  selectedPrescriptionId: null,
  selectedPrescriptionNumber: null,
  selectedMedicationName: null,
  selectedMedicationCode: null,
  selectedDose: null,
  selectedFrequency: null,
  selectedDuration: null,
  selectedPrescribedQuantity: null,
  selectedDosingInstruction: null,
  selectedPrescriberName: null,
  selectedTranscriptionNote: null,
  selectedRxStatus: null,
  selectedRxOccurredAt: null,
  selectedSource: null,
  selectedPatientId: null,
  selectedPatientDisplayName: null,
  selectedPatientNumber: null,
  selectedPatientDateOfBirth: null,
  selectedPatientSex: null,
  selectedPatientAllergies: null,
  view: null,
  viewPatient: null,
  dispensings: [],
  items: [
    { itemId: 'item-amox', code: 'AMOX-500', name: 'Amoxicillin 500 mg', uom: 'CAPSULE', active: true, itemType: 'MEDICATION', trackBatch: false },
    { itemId: 'item-amox-250', code: 'AMOX-250', name: 'Amoxicillin 250 mg', uom: 'CAPSULE', active: true, itemType: 'MEDICATION', trackBatch: false },
    { itemId: 'item-para', code: 'PARA-500', name: 'Paracetamol 500 mg', uom: 'TABLET', active: true, itemType: 'MEDICATION', trackBatch: false },
    { itemId: 'item-supply', code: 'STRIPS', name: 'Glucose test strips', uom: 'STRIP', active: true, itemType: 'SUPPLY', trackBatch: false },
  ],
  locations: [],
  selectedItemId: null,
  selectedItemCode: null,
  selectedItemName: null,
  selectedItemUom: null,
  availabilityStatus: 'NOT_CHECKED',
  stockByLocation: [],
  searchError: null,
  query: null,
  searchResults: null,
  transcribePatient: null,
  transcribeAllergies: [],
  transcribeAllergiesAvailable: true,
};

describe('pharmacy board (phase 0)', () => {
  function visiblePanel(): HTMLElement {
    const panel = document.querySelector('[role="tabpanel"]:not([hidden])');
    if (!(panel instanceof HTMLElement)) throw new Error('visible tab panel is missing');
    return panel;
  }

  function selectCatalogMedicine(panel: HTMLElement, search: string, name: string, code: string): void {
    fireEvent.change(within(panel).getByRole('combobox', { name: 'Search medication catalog' }), {
      target: { value: search },
    });
    fireEvent.click(within(panel).getByRole('option', { name: `${name} · ${code}` }));
  }

  function selectComboOption(panel: HTMLElement, label: string, option: string): void {
    fireEvent.change(within(panel).getByRole('combobox', { name: label }), {
      target: { value: option },
    });
    fireEvent.click(within(panel).getByRole('option', { name: option }));
  }

  function startExternalIntake(): HTMLElement {
    fireEvent.click(screen.getByRole('button', { name: 'External prescription intake' }));
    const confirmation = screen.getByRole('dialog', { name: 'Record an external prescription' });
    expect(confirmation).toHaveTextContent('paper prescription issued outside AfyaNova');
    expect(confirmation).toHaveTextContent('No clinic visit is created');
    fireEvent.click(within(confirmation).getByRole('button', { name: 'Continue' }));
    return screen.getByRole('dialog', { name: 'External prescription intake' });
  }

  beforeEach(() => {
    state.url = '/workspaces/pharmacy';
    vi.clearAllMocks();
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    }));
    globalThis.ResizeObserver ??= class {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    };
  });

  it('renders the orders board by default with patient identity and no dock', () => {
    render(<PharmacyBoard {...baseProps} />);

    const table = screen.getByRole('table', { name: 'Orders (1)' });
    for (const header of ['Order #', 'Created', 'Patient', 'Drugs', 'Order status']) {
      expect(within(table).getByRole('columnheader', { name: header })).toBeInTheDocument();
    }
    for (const removedHeader of ['Prescriber', 'Priority', 'Last dispenser']) {
      expect(within(table).queryByRole('columnheader', { name: removedHeader })).not.toBeInTheDocument();
    }
    expect(within(table).getByText('ORD-0001')).toBeInTheDocument();
    expect(within(table).getByText('Oct 1, 2026')).toBeInTheDocument();
    expect(within(table).getByText(/VALIDATED/)).toBeInTheDocument();
    // Patient is plain identity text — details open via row expansion, not a dock.
    expect(within(table).getByText(/Amina Juma/)).toBeInTheDocument();
    expect(within(table).queryByRole('link', { name: /Amina Juma/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Open patient chart' })).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(within(table).getByText('PAT-00000001 · 45 years · Female').parentElement)
      .toHaveClass('pharmacy-worklist-patient');
    expect(screen.queryByRole('table', { name: 'Prescriptions (1)' })).not.toBeInTheDocument();
  });

  it('expands the selected patient row from a Pharmacy record deep link', () => {
    state.url = '/workspaces/pharmacy?view=orders&order_id=order-1';
    render(
      <PharmacyBoard
        {...baseProps}
        selectedPatientId="patient-1"
        selectedOrderId="order-1"
        selectedOrderNumber="ORD-0001"
        selectedOrderStatus="VALIDATED"
      />,
    );

    expect(screen.getByText(/ORD-0001 · ROUTINE · Rx RX-0001/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Collapse Amina Juma · PAT-00000001' })).toBeInTheDocument();
  });

  it('shows patient age and sex in both prescription and order worklists', () => {
    const { rerender } = render(<PharmacyBoard {...baseProps} />);

    expect(screen.getAllByText(/45 years · Female/).length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('tab', { name: 'All prescriptions' }));
    expect(screen.getByRole('table', { name: 'Prescriptions (1)' })).toBeInTheDocument();
    expect(screen.getAllByText(/45 years · Female/).length).toBeGreaterThan(0);

    fireEvent.click(screen.getByRole('tab', { name: 'Orders' }));
    rerender(<PharmacyBoard {...baseProps} orders={[{
      ...baseProps.orders[0]!,
      patientAge: null,
      patientSex: 'UNKNOWN',
    }]} />);
    expect(screen.getAllByText('PAT-00000001').length).toBeGreaterThan(0);
  });

  it('falls back to orders for an unknown view', () => {
    state.url = '/workspaces/pharmacy?view=unknown';
    render(<PharmacyBoard {...baseProps} />);

    expect(screen.getByRole('table', { name: 'Orders (1)' })).toBeInTheDocument();
  });

  it('renders the prescriptions board on its view', () => {
    state.url = '/workspaces/pharmacy?view=prescriptions';
    render(<PharmacyBoard {...baseProps} />);

    const table = screen.getByRole('table', { name: 'Prescriptions (1)' });
    expect(within(table).getByRole('columnheader', { name: 'Created' })).toBeInTheDocument();
    expect(within(table).getByRole('columnheader', { name: 'Patient' })).toBeInTheDocument();
    expect(within(table).getByRole('columnheader', { name: 'Prescriber' })).toBeInTheDocument();
    expect(within(table).getByRole('columnheader', { name: 'Drugs' })).toBeInTheDocument();
    expect(within(table).getByRole('columnheader', { name: 'Last dispenser' })).toBeInTheDocument();
    expect(within(table).getByRole('columnheader', { name: 'Status' })).toBeInTheDocument();
    expect(within(table).getByText('Oct 1, 2026')).toBeInTheDocument();
    expect(within(table).getByText('Dr. Asha')).toBeInTheDocument();
    expect(within(table).getByText('Not dispensed')).toBeInTheDocument();
    expect(within(table).getAllByText('Amoxicillin').length).toBeGreaterThan(0);
    expect(within(table).getByText('ACTIVE (1)')).toBeInTheDocument();
    expect(screen.queryByRole('table', { name: 'Orders (1)' })).not.toBeInTheDocument();
  });

  it('shows the most recent dispenser in the prescription worklist', () => {
    state.url = '/workspaces/pharmacy?view=prescriptions';
    render(
      <PharmacyBoard
        {...baseProps}
        prescriptions={[{ ...baseProps.prescriptions[0]!, lastDispenserName: 'Pharmacist Asha' }]}
      />,
    );

    const table = screen.getByRole('table', { name: 'Prescriptions (1)' });
    expect(within(table).getByText('Pharmacist Asha')).toBeInTheDocument();
    expect(within(table).getByRole('columnheader', { name: 'Last dispenser' })).toBeInTheDocument();
  });

  it('expands the legacy patient view inline with no bottom panel', () => {
    state.url = '/workspaces/pharmacy?view=patient&patient_id=patient-1';
    render(
      <PharmacyIndex
        {...baseProps}
        view="patient"
        selectedPatientId="patient-1"
        selectedPatientAllergies={[{
          substanceName: 'Penicillin',
          severity: 'SEVERE',
          reaction: 'Anaphylaxis',
        }]}
        viewPatient={{
          patientId: 'patient-1',
          displayName: 'Amina Juma',
          patientNumber: 'PAT-00000001',
          prescriptions: baseProps.prescriptions,
          orders: baseProps.orders,
        }}
      />,
    );

    // No dock panel anymore — the patient's group expands inline in the boards.
    expect(screen.queryByRole('dialog', { name: 'Amina Juma medicines' })).not.toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Orders (1)' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Collapse Amina Juma · PAT-00000001' })).toBeInTheDocument();
    expect(screen.getByText(/ORD-0001 · ROUTINE · Rx RX-0001/)).toBeInTheDocument();
  });

  it('shows boards with no dock for a patient with no records', () => {
    render(
      <PharmacyIndex
        {...baseProps}
        view="patient"
        viewPatient={{
          patientId: 'patient-1',
          displayName: 'Amina Juma',
          patientNumber: 'PAT-00000001',
          prescriptions: [],
          orders: [],
        }}
      />,
    );

    expect(screen.queryByRole('dialog', { name: 'Amina Juma medicines' })).not.toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Orders (1)' })).toBeInTheDocument();
  });

  it('shows no dock or allergy line when allergy data is not loaded', () => {
    render(
      <PharmacyIndex
        {...baseProps}
        view="patient"
        viewPatient={{
          patientId: 'patient-1',
          displayName: 'Amina Juma',
          patientNumber: 'PAT-00000001',
          prescriptions: [],
          orders: [],
        }}
      />,
    );

    expect(screen.queryByRole('dialog', { name: 'Amina Juma medicines' })).not.toBeInTheDocument();
    expect(screen.queryByText(/Allergies:/)).not.toBeInTheDocument();
    expect(screen.queryByText('No recorded allergies.')).not.toBeInTheDocument();
  });

  it('groups orders by patient and keeps external paper prescription lines grouped by intake', () => {
    const firstPrescription = {
      ...baseProps.prescriptions[0]!,
      source: 'EXTERNAL_PAPER',
      externalIntakeId: 'intake-1',
    };
    const prescriptions = [
      firstPrescription,
      {
        ...firstPrescription,
        prescriptionId: 'rx-2',
        prescriptionNumber: 'RX-0002',
        medicationName: 'Paracetamol',
      },
      {
        ...firstPrescription,
        prescriptionId: 'rx-3',
        prescriptionNumber: 'RX-0003',
        medicationName: 'Cetirizine',
        externalIntakeId: 'intake-2',
      },
    ];
    const firstOrder = {
      ...baseProps.orders[0]!,
      externalIntakeId: 'intake-1',
    };
    const orders = [
      firstOrder,
      { ...firstOrder, pharmacyOrderId: 'order-2', orderNumber: 'ORD-0002', prescriptionId: 'rx-2', medicationName: 'Paracetamol' },
      { ...firstOrder, pharmacyOrderId: 'order-3', orderNumber: 'ORD-0003', prescriptionId: 'rx-3', medicationName: 'Cetirizine', externalIntakeId: 'intake-2' },
    ];
    state.url = '/workspaces/pharmacy?view=prescriptions';
    render(<PharmacyBoard {...baseProps} prescriptions={prescriptions} orders={orders} />);

    let table = screen.getByRole('table', { name: 'Prescriptions (3)' });
    expect(within(table).getAllByRole('row')).toHaveLength(2);
    expect(within(table).getByText('Amoxicillin, Paracetamol and 1 more')).toBeInTheDocument();
    expect(within(table).getByText('Not dispensed')).toBeInTheDocument();
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    expect(within(table).getByRole('region', { name: 'External paper intake · 2 medicines' })).toBeInTheDocument();
    expect(within(table).getByRole('region', { name: 'External paper intake · 1 medicine' })).toBeInTheDocument();
    expect(within(table).getByText('Paracetamol')).toBeInTheDocument();
    expect(within(table).getByText('Cetirizine')).toBeInTheDocument();
    expect(within(table).getByText(/RX-0002/)).toBeInTheDocument();
    expect(within(table).getAllByText('Amoxicillin').length).toBeGreaterThan(0);
    expect(within(table).getByText(/RX-0002/)).toBeInTheDocument();
    expect(within(table).getAllByRole('button', { name: 'Cancel prescription' }).length).toBeGreaterThan(0);

    fireEvent.click(screen.getByRole('tab', { name: 'Orders' }));
    table = screen.getByRole('table', { name: 'Orders (3)' });
    expect(within(table).getAllByRole('row')).toHaveLength(2);
    expect(within(table).getByText('ORD-0001 + 2 more')).toBeInTheDocument();
    fireEvent.change(screen.getByPlaceholderText('Search orders'), { target: { value: 'Paracetamol' } });
    expect(within(table).getByText(/Amina Juma/)).toBeInTheDocument();
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    expect(within(table).getAllByText(/ORD-0002/).length).toBeGreaterThan(0);
    expect(within(table).getAllByText(/ORD-0001/).length).toBeGreaterThan(0);
    expect(within(table).getAllByText('Amoxicillin').length).toBeGreaterThan(0);
    expect(within(table).getAllByText('Nothing dispensed yet').length).toBeGreaterThan(0);
    expect(within(table).getAllByText('500 mg').length).toBeGreaterThan(0);
  });

  it('summarizes all medicines in the patient row and shows all order details when expanded', () => {
    const orders = Array.from({ length: 10 }, (_, index) => ({
      ...baseProps.orders[0]!,
      pharmacyOrderId: `order-${index + 1}`,
      orderNumber: `ORD-${String(index + 1).padStart(4, '0')}`,
      medicationName: `Medicine ${index + 1}`,
    }));
    render(<PharmacyBoard {...baseProps} orders={orders} />);

    const table = screen.getByRole('table', { name: 'Orders (10)' });
    expect(within(table).getAllByRole('row')).toHaveLength(2);
    expect(within(table).getByText('Medicine 1, Medicine 2 and 8 more')).toBeInTheDocument();
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    for (let index = 1; index <= 10; index += 1) {
      expect(within(table).getByRole('heading', { name: `Medicine ${index}` })).toBeInTheDocument();
    }
    expect(within(table).queryByRole('button', { name: /View details for/ })).not.toBeInTheDocument();
  });

  it('shows the count when a patient has repeated orders for the same medicine', () => {
    const medicationName = baseProps.orders[0]!.medicationName!;
    render(<PharmacyBoard {...baseProps} orders={[
      baseProps.orders[0]!,
      { ...baseProps.orders[0]!, pharmacyOrderId: 'order-2', orderNumber: 'ORD-0002' },
    ]} />);

    const table = screen.getByRole('table', { name: 'Orders (2)' });
    expect(within(table).getByText(`${medicationName} × 2`)).toBeInTheDocument();
    expect(within(table).getByText('ORD-0001 + 1 more')).toBeInTheDocument();
  });

  it('renders empty states for empty boards', () => {
    const { unmount } = render(<PharmacyBoard {...baseProps} orders={[]} />);
    expect(within(visiblePanel()).getByText('No orders to display')).toBeInTheDocument();
    unmount();

    state.url = '/workspaces/pharmacy?view=prescriptions';
    render(<PharmacyBoard {...baseProps} prescriptions={[]} />);
    expect(within(visiblePanel()).getByText('No prescriptions to display')).toBeInTheDocument();
  });

  it('filters board rows through the toolbar search', () => {
    render(<PharmacyBoard {...baseProps} orders={[
      ...baseProps.orders,
      {
        pharmacyOrderId: 'order-2',
        orderNumber: 'ORD-0002',
        prescriptionId: 'rx-1',
        patientId: 'patient-2',
        patientDisplayName: 'Juma Mwinyi',
        patientNumber: 'PAT-00000002',
        medicationName: 'Paracetamol',
        status: 'RECEIVED',
        priority: 'URGENT',
        dispensingCount: 0,
        occurredAt: '2026-10-02T10:00:00Z',
        dispensings: [],
        externalIntakeId: null,
      },
    ]} />);

    fireEvent.change(screen.getByPlaceholderText('Search orders'), { target: { value: 'ORD-0002' } });
    const table = screen.getByRole('table', { name: 'Orders (2)' });
    expect(within(table).queryByText(/Amina Juma/)).not.toBeInTheDocument();
    expect(within(table).getByText(/Juma Mwinyi/)).toBeInTheDocument();
    expect(within(table).getAllByRole('row')).toHaveLength(2);
  });

  it('stays read-only in phase 0 with permission flags plumbed but no mutation actions', () => {
    render(<PharmacyBoard {...baseProps} />);

    for (const name of ['Validate', 'Reject', 'Hold', 'Dispense', 'Transcribe', 'Record pickup']) {
      expect(screen.queryByRole('button', { name })).not.toBeInTheDocument();
    }
  });

  it('expands the legacy patient view inline with no bottom panel', () => {
    state.url = '/workspaces/pharmacy?view=patient&patient_id=patient-1';
    render(<PharmacyBoard
      {...baseProps}
      view="patient"
      selectedPatientAllergies={[{ substanceName: 'Penicillin', severity: 'MODERATE', reaction: 'Rash' }]}
      viewPatient={{
        patientId: 'patient-1',
        displayName: 'Amina Juma',
        patientNumber: 'PAT-00000001',
        prescriptions: baseProps.prescriptions,
        orders: baseProps.orders,
      }}
    />);

    expect(screen.queryByRole('dialog', { name: 'Amina Juma medicines' })).not.toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Orders (1)' })).toBeInTheDocument();
  });

  it('falls back to the orders board for a patient view without a selected patient', () => {
    state.url = '/workspaces/pharmacy?view=patient';
    render(<PharmacyBoard {...baseProps} />);

    expect(screen.getByRole('tab', { name: 'Orders' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('table', { name: 'Orders (1)' })).toBeInTheDocument();
  });

  it('switches board views through tabs locally with no page reload', () => {
    render(<PharmacyBoard {...baseProps} />);

    expect(document.querySelector('.pharmacy-board__tabs')).not.toBeNull();

    expect(screen.getByRole('tab', { name: 'Orders' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('tab', { name: 'Active prescriptions' })).toHaveAttribute('aria-selected', 'false');
    expect(screen.getByRole('tab', { name: 'All prescriptions' })).toHaveAttribute('aria-selected', 'false');
    expect(screen.queryByRole('tab', { name: 'Patient' })).not.toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Orders (1)' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: 'Active prescriptions' }));
    expect(screen.getByRole('table', { name: 'Active prescriptions (1)' })).toBeInTheDocument();
    expect(screen.queryByRole('table', { name: 'Orders (1)' })).not.toBeInTheDocument();
    expect(router.get).not.toHaveBeenCalled();
    expect(router.visit).not.toHaveBeenCalled();
  });

  it('marks the active prescriptions tab from its deep link', () => {
    state.url = '/workspaces/pharmacy?view=prescriptions&rx_status=ACTIVE';
    render(<PharmacyBoard {...baseProps} />);

    expect(screen.getByRole('tab', { name: 'Active prescriptions' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('tab', { name: 'All prescriptions' })).toHaveAttribute('aria-selected', 'false');
  });

  it('switches to the orders tab when an order is selected after a server visit', () => {
    state.url = '/workspaces/pharmacy?view=prescriptions';
    const { rerender } = render(<PharmacyBoard {...baseProps} />);
    expect(screen.getByRole('tab', { name: 'All prescriptions' })).toHaveAttribute('aria-selected', 'true');

    state.url = '/workspaces/pharmacy?view=orders&order_id=order-1';
    act(() => rerender(<PharmacyBoard {...baseProps} selectedOrderId="order-1" />));

    expect(screen.getByRole('tab', { name: 'Orders' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.queryByRole('dialog', { name: 'Order ORD-0001' })).not.toBeInTheDocument();
    const table = screen.getByRole('table', { name: 'Orders (1)' });
    expect(within(table).getByRole('button', { name: 'Collapse Amina Juma · PAT-00000001' })).toBeInTheDocument();
    expect(within(table).getAllByText(/ORD-0001/).length).toBeGreaterThan(0);
  });

  it('shows only active prescriptions on the active tab', () => {
    render(<PharmacyBoard
      {...baseProps}
      prescriptions={[
        ...baseProps.prescriptions,
        {
          prescriptionId: 'rx-2',
          prescriptionNumber: 'RX-0002',
          patientId: 'patient-2',
          patientDisplayName: 'Juma Mwinyi',
          patientNumber: 'PAT-00000002',
          source: 'PAPER_TRANSCRIPTION',
          status: 'CANCELLED',
          medicationName: null,
          medicationCode: null,
          inventoryItemId: null,
          prescriberName: null,
          occurredAt: '2026-10-01',
          recordedAt: '2026-10-01T10:00:00Z',
          route: null,
          urgency: null,
          dose: null,
          frequency: null,
          duration: null,
          prescribedQuantity: null,
          dosingInstruction: null,
          transcriptionNote: null,
          externalIntakeId: null,
          lastDispenserName: null,
        },
      ]}
    />);

    fireEvent.click(screen.getByRole('tab', { name: 'Active prescriptions' }));
    expect(screen.getByRole('table', { name: 'Active prescriptions (1)' })).toBeInTheDocument();
    const activePrescriptionTable = within(visiblePanel()).getByRole('table', { name: 'Active prescriptions (1)' });
    expect(within(activePrescriptionTable).queryByText('Juma Mwinyi')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: 'All prescriptions' }));
    expect(screen.getByRole('table', { name: 'Prescriptions (2)' })).toBeInTheDocument();
    const prescriptionTable = within(visiblePanel()).getByRole('table', { name: 'Prescriptions (2)' });
    fireEvent.click(within(prescriptionTable).getByRole('button', { name: 'Expand Juma Mwinyi · PAT-00000002' }));
    expect(within(prescriptionTable).getByText('PAPER TRANSCRIPTION · RX-0002')).toBeInTheDocument();
    expect(within(prescriptionTable).getByText('No directions recorded.')).toBeInTheDocument();
    expect(router.get).not.toHaveBeenCalled();
  });

  it('keeps boards visible with no patient tab and no dock even when patient context exists', () => {
    state.url = '/workspaces/pharmacy?view=patient&patient_id=patient-1';
    render(<PharmacyBoard
      {...baseProps}
      view="patient"
      viewPatient={{
        patientId: 'patient-1',
        displayName: 'Amina Juma',
        patientNumber: 'PAT-00000001',
        prescriptions: [],
        orders: [],
      }}
    />);

    expect(screen.queryByRole('tab', { name: 'Patient' })).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Orders (1)' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: 'Orders' }));
    const cleanedUrl = (router.get as ReturnType<typeof vi.fn>).mock.calls[0]?.[0] as string;
    expect(cleanedUrl).toContain('view=orders');
    expect(cleanedUrl).not.toContain('patient_id');
    expect(router.visit).not.toHaveBeenCalled();
  });

  it('shows boards regardless of patient context clearing', () => {
    state.url = '/workspaces/pharmacy?view=patient&patient_id=patient-1';
    const { rerender } = render(<PharmacyBoard
      {...baseProps}
      view="patient"
      viewPatient={{
        patientId: 'patient-1',
        displayName: 'Amina Juma',
        patientNumber: 'PAT-00000001',
        prescriptions: [],
        orders: [],
      }}
    />);
    expect(screen.getByRole('table', { name: 'Orders (1)' })).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

    state.url = '/workspaces/pharmacy?view=orders';
    rerender(<PharmacyBoard {...baseProps} viewPatient={null} />);
    expect(screen.getByRole('table', { name: 'Orders (1)' })).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('shows complete order details inline when an order row expands', () => {
    render(<PharmacyBoard {...baseProps} />);

    const table = screen.getByRole('table', { name: 'Orders (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));

    expect(within(table).getAllByText(/ORD-0001/).length).toBeGreaterThan(0);
    expect(within(table).getAllByText('Amoxicillin').length).toBeGreaterThan(0);
    expect(within(table).getByText('Take by mouth')).toBeInTheDocument();
    expect(within(table).getAllByText('Nothing dispensed yet').length).toBeGreaterThan(0);
    expect(router.get).not.toHaveBeenCalled();
    expect(router.visit).not.toHaveBeenCalled();
  });

  it('renders dispensing details and lifecycle actions inline with an expanded order', () => {
    const order = baseProps.orders[0]!;
    render(<PharmacyBoard
      {...baseProps}
      orders={[{
        ...order,
        dispensings: [{
          dispensingId: 'disp-1',
          dispensingNumber: 'DISP-0001',
          itemCode: 'AMOX-500',
          itemName: 'Amoxicillin 500mg',
          quantity: '30',
          quantityUnit: 'capsules',
          status: 'COMPLETED',
          pickupStatus: 'READY_FOR_PICKUP',
          reversalId: null,
          receivedAt: '2026-10-02',
        }],
      }]}
    />);

    const table = screen.getByRole('table', { name: 'Orders (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    expect(within(table).getByText('VALIDATED')).toBeInTheDocument();
    expect(within(table).getByText(/ROUTINE/)).toBeInTheDocument();
    expect(within(table).getByText('500 mg')).toBeInTheDocument();
    expect(within(table).getByText('Once daily')).toBeInTheDocument();
    expect(within(table).getByText('10 tablets')).toBeInTheDocument();
    expect(within(table).getByText('Take by mouth')).toBeInTheDocument();
    expect(within(table).getByText(/RX-0001/)).toBeInTheDocument();
    expect(within(table).getByRole('button', { name: 'Hold' })).toBeInTheDocument();
    expect(within(table).getByRole('button', { name: 'Reject' })).toBeInTheDocument();
    expect(within(table).getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    expect(within(table).queryByRole('button', { name: 'Validate' })).not.toBeInTheDocument();
    expect(within(table).queryByRole('button', { name: 'Release' })).not.toBeInTheDocument();
    expect(within(table).getByText(/DISP-0001/)).toBeInTheDocument();
  });

  it('collapses the inline order detail without changing the URL', () => {
    render(<PharmacyBoard {...baseProps} />);
    const table = screen.getByRole('table', { name: 'Orders (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    expect(within(table).getByText('ORD-0001 · ROUTINE · Rx RX-0001')).toBeInTheDocument();
    fireEvent.click(within(table).getByRole('button', { name: 'Collapse Amina Juma · PAT-00000001' }));
    expect(within(table).queryByText('ORD-0001 · ROUTINE · Rx RX-0001')).not.toBeInTheDocument();
    expect(router.visit).not.toHaveBeenCalled();
    expect(router.get).not.toHaveBeenCalled();
  });

  it('gates detail actions by backend status', () => {
    const { unmount } = render(<PharmacyBoard
      {...baseProps}
      orders={[{ ...baseProps.orders[0]!, status: 'RECEIVED' }]}
    />);

    let table = screen.getByRole('table', { name: 'Orders (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    let panel = table;
    expect(within(panel).getByRole('button', { name: 'Validate' })).toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Hold' })).toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Reject' })).toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    expect(within(panel).queryByRole('button', { name: 'Release' })).not.toBeInTheDocument();
    unmount();

    const { unmount: unmountHold } = render(<PharmacyBoard
      {...baseProps}
      orders={[{ ...baseProps.orders[0]!, status: 'ON_HOLD' }]}
    />);
    table = screen.getByRole('table', { name: 'Orders (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    panel = table;
    expect(within(panel).getByRole('button', { name: 'Release' })).toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    expect(within(panel).queryByRole('button', { name: 'Validate' })).not.toBeInTheDocument();
    expect(within(panel).queryByRole('button', { name: 'Hold' })).not.toBeInTheDocument();
    expect(within(panel).queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
    unmountHold();

    render(<PharmacyBoard
      {...baseProps}
      orders={[{ ...baseProps.orders[0]!, status: 'CANCELLED' }]}
    />);
    table = screen.getByRole('table', { name: 'Orders (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    panel = table;
    for (const name of ['Validate', 'Hold', 'Reject', 'Release', 'Cancel']) {
      expect(within(panel).queryByRole('button', { name })).not.toBeInTheDocument();
    }
  });

  it('hides detail actions without order management permission', () => {
    render(<PharmacyBoard
      {...baseProps}
      canManageOrders={false}
      orders={[{ ...baseProps.orders[0]!, status: 'RECEIVED' }]}
    />);

    const panel = screen.getByRole('table', { name: 'Orders (1)' });
    fireEvent.click(within(panel).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    for (const name of ['Validate', 'Hold', 'Reject', 'Release', 'Cancel']) {
      expect(within(panel).queryByRole('button', { name })).not.toBeInTheDocument();
    }
  });

  it('validates an order directly from its detail', () => {
    render(<PharmacyBoard
      {...baseProps}
      orders={[{ ...baseProps.orders[0]!, status: 'RECEIVED' }]}
    />);

    const table = screen.getByRole('table', { name: 'Orders (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    fireEvent.click(within(table).getByRole('button', { name: 'Validate' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/orders/order-1/validate',
      {},
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('rejects an order through the shared reason dialog', () => {
    render(<PharmacyBoard
      {...baseProps}
      orders={[{ ...baseProps.orders[0]!, status: 'VALIDATED' }]}
    />);

    const table = screen.getByRole('table', { name: 'Orders (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    fireEvent.click(within(table).getByRole('button', { name: 'Reject' }));
    const dialog = screen.getByRole('dialog', { name: 'Reject order' });
    expect(within(dialog).getByRole('button', { name: 'Reject order' })).toBeDisabled();

    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'DUPLICATE' } });
    fireEvent.change(within(dialog).getByLabelText('Note (optional)'), {
      target: { value: 'Ordered twice by mistake.' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reject order' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/orders/order-1/reject',
      { reason_code: 'DUPLICATE', note: 'Ordered twice by mistake.' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('filters orders by status inside the table toolbar', () => {
    render(<PharmacyBoard {...baseProps} orders={[
      ...baseProps.orders,
      {
        pharmacyOrderId: 'order-2',
        orderNumber: 'ORD-0002',
        prescriptionId: 'rx-1',
        patientId: 'patient-2',
        patientDisplayName: 'Juma Mwinyi',
        patientNumber: 'PAT-00000002',
        medicationName: 'Paracetamol',
        status: 'RECEIVED',
        priority: 'URGENT',
        dispensingCount: 0,
        occurredAt: '2026-10-02T10:00:00Z',
        dispensings: [],
        externalIntakeId: null,
      },
    ]} />);

    fireEvent.click(screen.getByRole('button', { name: 'Filter by status: All statuses' }));
    const items = screen.getAllByRole('menuitem', { hidden: true });
    const received = items.find((item) => item.textContent?.startsWith('RECEIVED ('));
    if (!received) throw new Error('RECEIVED filter item missing');
    fireEvent.click(received);

    const table = screen.getByRole('table', { name: 'Orders (1)' });
    expect(within(table).getAllByRole('row')).toHaveLength(2);
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Juma Mwinyi · PAT-00000002' }));
    expect(within(table).queryByText('ORD-0001')).not.toBeInTheDocument();
    expect(within(table).getAllByText(/ORD-0002/)).toHaveLength(2);
    expect(screen.getByRole('button', { name: 'Filter by status: RECEIVED' })).toBeInTheDocument();
    expect(router.get).not.toHaveBeenCalled();
  });

  it('shows prescription details inline and creates an order from an active prescription', () => {
    state.url = '/workspaces/pharmacy?view=prescriptions';
    render(<PharmacyBoard {...baseProps} orders={[]} />);

    const prescriptionTable = screen.getByRole('table', { name: 'Prescriptions (1)' });
    fireEvent.click(within(prescriptionTable).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    expect(within(prescriptionTable).getAllByText('Amoxicillin').length).toBeGreaterThan(0);
    expect(within(prescriptionTable).getByText('500 mg')).toBeInTheDocument();
    expect(within(prescriptionTable).getByText('Once daily')).toBeInTheDocument();
    expect(within(prescriptionTable).getByText('5 days')).toBeInTheDocument();
    expect(within(prescriptionTable).getByText('10 tablets')).toBeInTheDocument();
    expect(within(prescriptionTable).getByText('Take by mouth')).toBeInTheDocument();
    fireEvent.click(within(prescriptionTable).getByRole('button', { name: 'Create order' }));
    const createPanel = screen.getByRole('dialog', { name: 'Create order for RX-0001' });
    fireEvent.change(within(createPanel).getByLabelText('Priority'), { target: { value: 'URGENT' } });
    fireEvent.click(within(createPanel).getByRole('button', { name: 'Create order' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/orders',
      { prescription_id: 'rx-1', priority: 'URGENT' },
      expect.objectContaining({ preserveScroll: true }),
    );
    expect(router.get).not.toHaveBeenCalled();
  });

  it('links an active prescription to its existing pharmacy order instead of offering duplicate creation', () => {
    state.url = '/workspaces/pharmacy?view=prescriptions';
    render(<PharmacyBoard {...baseProps} />);
    const table = screen.getByRole('table', { name: 'Prescriptions (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));

    expect(within(table).queryByRole('button', { name: 'Create order' })).not.toBeInTheDocument();
    expect(within(table).getByText('ORD-0001')).toBeInTheDocument();
    expect(within(table).getByText(/VALIDATED/)).toBeInTheDocument();
    expect(router.get).not.toHaveBeenCalled();
  });

  it('offers no order creation for non-active prescriptions', () => {
    const source = baseProps.prescriptions[0];
    if (!source) throw new Error('prescription fixture is missing');
    state.url = '/workspaces/pharmacy?view=prescriptions';
    render(<PharmacyBoard
      {...baseProps}
      prescriptions={[{ ...source, status: 'CANCELLED' }]}
    />);

    const table = screen.getByRole('table', { name: 'Prescriptions (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    expect(within(table).queryByRole('button', { name: 'Create order' })).not.toBeInTheDocument();
  });

  it('surfaces page errors as an alert', () => {
    render(<PharmacyIndex {...baseProps} errors={{ order: 'Order is no longer receivable.' }} />);

    expect(screen.getByRole('alert')).toHaveTextContent('Order is no longer receivable.');
  });

  it('cancels an active prescription through a reason modal', () => {
    state.url = '/workspaces/pharmacy?view=prescriptions';
    render(<PharmacyBoard {...baseProps} />);

    const table = screen.getByRole('table', { name: 'Prescriptions (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    fireEvent.click(within(table).getByRole('button', { name: 'Cancel prescription' }));

    const dialog = screen.getByRole('dialog', { name: 'Cancel prescription' });
    expect(within(dialog).getByRole('button', { name: 'Cancel prescription' })).toBeDisabled();
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'No' } });
    expect(within(dialog).getByRole('button', { name: 'Cancel prescription' })).toBeDisabled();
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Duplicate therapy' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel prescription' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/prescriptions/rx-1/cancel',
      { reason: 'Duplicate therapy' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('offers no cancel action for cancelled prescriptions or without transcribe permission', () => {
    state.url = '/workspaces/pharmacy?view=prescriptions';
    const source = baseProps.prescriptions[0];
    if (!source) throw new Error('prescription fixture is missing');
    const { unmount } = render(<PharmacyBoard
      {...baseProps}
      prescriptions={[{ ...source, status: 'CANCELLED' }]}
    />);
    let table = screen.getByRole('table', { name: 'Prescriptions (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    expect(within(table).queryByRole('button', { name: 'Cancel prescription' })).not.toBeInTheDocument();
    unmount();

    render(<PharmacyIndex
      {...baseProps}
      canTranscribe={false}
    />);
    table = screen.getByRole('table', { name: 'Prescriptions (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    expect(within(table).queryByRole('button', { name: 'Cancel prescription' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'External prescription intake' })).not.toBeInTheDocument();
  });

  it('accepts a received electronic prescription and posts to the accept route', () => {
    const source = baseProps.prescriptions[0];
    if (!source) throw new Error('prescription fixture is missing');
    state.url = '/workspaces/pharmacy?view=prescriptions';
    render(<PharmacyBoard
      {...baseProps}
      orders={[]}
      prescriptions={[{ ...source, source: 'ELECTRONIC', status: 'RECEIVED', medicationName: null }]}
    />);

    const table = screen.getByRole('table', { name: 'Prescriptions (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    fireEvent.click(within(table).getByRole('button', { name: 'Accept prescription' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/prescriptions/rx-1/accept',
      {},
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('offers no accept action for active prescriptions, or without transcribe permission', () => {
    const source = baseProps.prescriptions[0];
    if (!source) throw new Error('prescription fixture is missing');
    state.url = '/workspaces/pharmacy?view=prescriptions';

    const { unmount } = render(<PharmacyBoard
      {...baseProps}
      orders={[]}
      prescriptions={[{ ...source, source: 'ELECTRONIC', status: 'ACTIVE' }]}
    />);
    let table = screen.getByRole('table', { name: 'Prescriptions (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    expect(within(table).queryByRole('button', { name: 'Accept prescription' })).not.toBeInTheDocument();
    unmount();

    // A RECEIVED electronic prescription with no transcribe permission stays un-actionable.
    render(<PharmacyBoard
      {...baseProps}
      canTranscribe={false}
      orders={[]}
      prescriptions={[{ ...source, source: 'ELECTRONIC', status: 'RECEIVED' }]}
    />);
    table = screen.getByRole('table', { name: 'Prescriptions (1)' });
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    expect(within(table).queryByRole('button', { name: 'Accept prescription' })).not.toBeInTheDocument();
  });

  it('keeps the external intake action visible beside the page title on every tab', () => {
    state.url = '/workspaces/pharmacy';
    render(<PharmacyIndex {...baseProps} />);

    fireEvent.click(screen.getByRole('button', { name: 'External prescription intake' }));
    expect(screen.getByRole('dialog', { name: 'Record an external prescription' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    const intake = screen.getByRole('dialog', { name: 'External prescription intake' });
    expect(intake).toBeInTheDocument();
    expect(within(intake).queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
    expect(within(intake).queryByRole('button', { name: 'Record prescription' })).not.toBeInTheDocument();
  });

  it('opens external intake with no patient panel in the way', () => {
    state.url = '/workspaces/pharmacy?view=patient&patient_id=patient-1';
    const viewPatient = {
      patientId: 'patient-1',
      displayName: 'Amina Juma',
      patientNumber: 'PAT-00000001',
      prescriptions: baseProps.prescriptions,
      orders: baseProps.orders,
    };
    const { rerender } = render(<PharmacyIndex
      {...baseProps}
      view="patient"
      viewPatient={viewPatient}
    />);

    expect(screen.queryByRole('dialog', { name: 'Amina Juma medicines' })).not.toBeInTheDocument();
    startExternalIntake();
    expect(router.get).toHaveBeenCalledWith(
      '/workspaces/pharmacy?external_intake=1',
      {},
      expect.objectContaining({ preserveState: true, replace: true }),
    );

    state.url = '/workspaces/pharmacy?external_intake=1';
    rerender(<PharmacyIndex {...baseProps} externalIntake />);
    expect(screen.getByRole('dialog', { name: 'External prescription intake' })).toBeInTheDocument();
  });

  it('keeps the selected intake patient in the external-intake panel', () => {
    state.url = '/workspaces/pharmacy?external_intake=1&view=orders';
    const selectedPatient = {
      patientId: 'patient-1',
      patientNumber: 'PAT-00000001',
      displayName: 'Amina Juma',
    };
    const { rerender } = render(<PharmacyIndex {...baseProps} externalIntake />);

    expect(screen.getByRole('dialog', { name: 'External prescription intake' })).toBeInTheDocument();
    rerender(<PharmacyIndex
      {...baseProps}
      externalIntake
      view="orders"
      transcribePatient={selectedPatient}
    />);

    expect(screen.getByRole('dialog', { name: 'External prescription intake' })).toBeInTheDocument();
    expect(screen.queryByRole('dialog', { name: 'Amina Juma medicines' })).not.toBeInTheDocument();
    expect(screen.getAllByText('Amina Juma').length).toBeGreaterThan(0);
  });

  it('keeps external intake open while working the boards (no patient dock to switch to)', () => {
    state.url = '/workspaces/pharmacy?external_intake=1';
    render(<PharmacyIndex {...baseProps} externalIntake />);

    expect(screen.getByRole('dialog', { name: 'External prescription intake' })).toBeInTheDocument();
    const table = screen.getByRole('table', { name: 'Orders (1)' });
    // Row expansion is fully local — it never navigates away from intake.
    fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
    expect(screen.getByRole('dialog', { name: 'External prescription intake' })).toBeInTheDocument();
    expect(router.get).not.toHaveBeenCalled();
    expect(router.visit).not.toHaveBeenCalled();
  });

  it('cancels external prescription confirmation without opening intake', () => {
    render(<PharmacyIndex {...baseProps} />);

    fireEvent.click(screen.getByRole('button', { name: 'External prescription intake' }));
    const confirmation = screen.getByRole('dialog', { name: 'Record an external prescription' });
    expect(confirmation).toHaveTextContent('paper prescription issued outside AfyaNova');
    expect(confirmation).toHaveTextContent('No clinic visit is created');
    fireEvent.click(within(confirmation).getByRole('button', { name: 'Cancel' }));

    expect(screen.queryByRole('dialog', { name: 'External prescription intake' })).not.toBeInTheDocument();
  });

  it('clears selected patient context when closing transcription and reopens at search', () => {
    state.url = '/workspaces/pharmacy?view=patient&patient_id=patient-1';
    const { rerender } = render(<PharmacyIndex
      {...baseProps}
      transcribePatient={{ patientId: 'patient-1', patientNumber: 'PAT-00000001', displayName: 'Amina Juma' }}
    />);

    startExternalIntake();
    expect(screen.getByRole('dialog', { name: 'External prescription intake' }))
      .toHaveTextContent('Amina Juma');
    fireEvent.click(screen.getByRole('button', { name: 'Close External prescription intake' }));

    expect(router.get).toHaveBeenCalledWith(
      '/workspaces/pharmacy',
      {},
      expect.objectContaining({ preserveState: true, replace: true }),
    );
    expect(screen.queryByRole('dialog', { name: 'External prescription intake' })).not.toBeInTheDocument();

    state.url = '/workspaces/pharmacy';
    rerender(<PharmacyIndex {...baseProps} transcribePatient={null} />);
    startExternalIntake();
    expect(screen.getByRole('dialog', { name: 'External prescription intake' }))
      .toHaveTextContent('Find a patient');
    expect(screen.getByLabelText('Find a patient')).toBeInTheDocument();
  });

  it('finds the patient through the shared typeahead search', async () => {
    vi.useFakeTimers();
    try {
      state.getJson.mockResolvedValue({
        patients: [{
          patientId: 'patient-1',
          patientNumber: 'PAT-00000001',
          displayName: 'Amina Juma',
          sex: 'Female',
          formattedDob: '02-Jun-1994',
          age: '32 yrs',
          photoUrl: null,
          activeEncounter: { encounterNumber: 'ENC-0001' },
        }],
        total: 1,
        currentPage: 1,
        lastPage: 1,
      });
      state.url = '/workspaces/pharmacy?view=prescriptions';
      render(<PharmacyIndex {...baseProps} />);

      fireEvent.click(screen.getByRole('tab', { name: 'All prescriptions' }));
      startExternalIntake();
      const panel = screen.getByRole('dialog', { name: 'External prescription intake' });
      fireEvent.change(within(panel).getByLabelText('Find a patient'), { target: { value: 'Amina' } });
      act(() => {
        vi.advanceTimersByTime(300);
      });
      await act(async () => {});

      expect(state.getJson).toHaveBeenCalledWith(expect.stringContaining('q=Amina'));
      expect(within(panel).getByText(/Search is limited to patients registered at this facility/)).toBeInTheDocument();
      expect(within(panel).getByText(/Female · 32 yrs · DOB 02-Jun-1994/)).toBeInTheDocument();
      expect(within(panel).getByText(/PAT-00000001/)).toBeInTheDocument();
      fireEvent.click(within(panel).getByRole('button', { name: /Amina Juma/ }));
      expect(router.get).toHaveBeenCalledWith(
        expect.stringContaining('patient_id=patient-1&external_intake=1'),
        {},
        expect.objectContaining({ preserveState: true, replace: true }),
      );
    } finally {
      vi.useRealTimers();
    }
  });

  it('keeps facility-scoped patient results selectable for external intake', async () => {
    vi.useFakeTimers();
    try {
      state.getJson.mockResolvedValue({
        patients: [{
          patientId: 'patient-1',
          patientNumber: 'PAT-00000001',
          displayName: 'Amina Juma',
          sex: 'Female',
          formattedDob: '02-Jun-1994',
          age: '32 yrs',
          photoUrl: null,
          activeEncounter: null,
        }],
        total: 1,
        currentPage: 1,
        lastPage: 1,
      });
      render(<PharmacyIndex {...baseProps} />);

      startExternalIntake();
      const panel = screen.getByRole('dialog', { name: 'External prescription intake' });
      fireEvent.change(within(panel).getByLabelText('Find a patient'), { target: { value: 'Amina' } });
      act(() => {
        vi.advanceTimersByTime(300);
      });
      await act(async () => {});

      expect(within(panel).getByText(/PAT-00000001/)).toBeInTheDocument();
      fireEvent.click(within(panel).getByRole('button', { name: /Amina Juma/ }));
      expect(router.get).toHaveBeenCalledWith(
        expect.stringContaining('patient_id=patient-1'),
        {},
        expect.objectContaining({ preserveState: true, replace: true }),
      );
    } finally {
      vi.useRealTimers();
    }
  });

  it('offers a minimal pharmacy-only patient record when no facility match exists', async () => {
    vi.useFakeTimers();
    try {
      state.getJson.mockResolvedValue({
        patients: [],
        total: 0,
        currentPage: 1,
        lastPage: 1,
      });
      render(<PharmacyIndex {...baseProps} />);
      startExternalIntake();
      const panel = screen.getByRole('dialog', { name: 'External prescription intake' });
      fireEvent.change(within(panel).getByLabelText('Find a patient'), { target: { value: 'New patient' } });
      act(() => {
        vi.advanceTimersByTime(300);
      });
      await act(async () => {});

      const createPatientButton = within(panel).getByRole('button', { name: 'Create minimal pharmacy patient record' });
      expect(createPatientButton).toHaveClass('afyanova-pharmacy-create-patient');
      fireEvent.click(createPatientButton);
      fireEvent.change(within(panel).getByLabelText('Given name'), { target: { value: 'Asha' } });
      fireEvent.change(within(panel).getByLabelText('Family name'), { target: { value: 'Mushi' } });
      const createRecord = within(panel).getByRole('button', { name: 'Create record and continue' });
      const backToSearch = within(panel).getByRole('button', { name: 'Back to search' });
      expect(createRecord).toHaveClass('cds--btn--primary');
      expect(backToSearch).toHaveClass('cds--btn--secondary');
      expect(createRecord.closest('.afy-dock__actions')).not.toBeNull();
      expect(backToSearch.closest('.afy-dock__actions')).toBe(createRecord.closest('.afy-dock__actions'));
      expect(within(panel).getByLabelText('Given name').closest('.afy-dock__content'))
        .not.toContainElement(createRecord);
      fireEvent.click(createRecord);

      expect(router.post).toHaveBeenCalledWith(
        '/workspaces/pharmacy/external-patients',
        expect.objectContaining({
          given_name: 'Asha',
          family_name: 'Mushi',
          sex: 'UNKNOWN',
          primary_phone: null,
        }),
        expect.objectContaining({ preserveScroll: true }),
      );
    } finally {
      vi.useRealTimers();
    }
  });

  it('changes the fill patient back to search and resets the basket', () => {
    const { rerender } = render(<PharmacyIndex
      {...baseProps}
      transcribePatient={{ patientId: 'patient-1', patientNumber: 'PAT-00000001', displayName: 'Amina Juma' }}
    />);

    startExternalIntake();
    const panel = screen.getByRole('dialog', { name: 'External prescription intake' });

    fireEvent.change(within(panel).getByLabelText('Prescriber name'), { target: { value: 'Dr. Asha' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Add drug' }));
    selectCatalogMedicine(panel, 'Amoxicillin', 'Amoxicillin 500 mg', 'AMOX-500');
    fireEvent.click(within(panel).getByRole('button', { name: 'Add to basket' }));
    expect(within(panel).getByRole('heading', { name: 'Prescription medicines (1)' })).toBeInTheDocument();

    const changePatientButton = within(panel).getByRole('button', { name: 'Change patient' });
    expect(panel.querySelector('.afy-dock__header')?.contains(changePatientButton)).toBe(true);
    expect(panel.querySelector('.afy-dock__content')?.contains(changePatientButton)).toBe(false);
    fireEvent.click(changePatientButton);
    const changedUrl = (router.get as ReturnType<typeof vi.fn>).mock.calls[0]?.[0] as string;
    expect(changedUrl).not.toContain('patient_id');

    rerender(<PharmacyIndex {...baseProps} transcribePatient={null} />);
    const searchPanel = screen.getByRole('dialog', { name: 'External prescription intake' });
    expect(within(searchPanel).getByLabelText('Find a patient')).toBeInTheDocument();
  });

  it('submits an external transcription without requiring a clinic visit', () => {
    vi.mocked(router.post).mockImplementation((_url, _data, options) => {
      (options as { onSuccess?: () => void } | undefined)?.onSuccess?.();
    });
    render(<PharmacyIndex
      {...baseProps}
      transcribePatient={{ patientId: 'patient-1', patientNumber: 'PAT-00000001', displayName: 'Amina Juma' }}
      transcribeAllergies={[{ substanceName: 'Penicillin', severity: 'MODERATE', reaction: 'Rash' }]}
    />);

    startExternalIntake();
    const panel = screen.getByRole('dialog', { name: 'External prescription intake' });
    expect(within(panel).queryByText('External prescription · no clinic visit required')).not.toBeInTheDocument();
    const allergyAlert = within(within(panel).getByRole('region', { name: 'Selected patient' })).getByRole('alert');
    expect(allergyAlert).toHaveTextContent('Recorded allergies');
    expect(allergyAlert).toHaveTextContent('Penicillin (MODERATE, Rash)');
    expect(within(panel).getByRole('button', { name: 'Record prescription' })).toBeDisabled();

    fireEvent.change(within(panel).getByLabelText('Prescriber name'), { target: { value: 'Dr. Asha' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Add drug' }));
    expect(within(panel).queryByRole('button', { name: 'Record prescription' })).not.toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Add to basket' })).toBeDisabled();
    expect(within(panel).getByRole('button', { name: 'Add to basket' })).toHaveClass('cds--btn--primary');
    expect(within(panel).getByRole('button', { name: 'Add to basket' }).closest('.afy-dock__actions')).not.toBeNull();
    expect(within(panel).getByRole('button', { name: 'Add to basket' })).not.toHaveClass('cds--btn--sm');
    expect(within(panel).getByRole('button', { name: 'Discard drug' })).toHaveClass('cds--btn--secondary');
    expect(within(panel).getByRole('button', { name: 'Discard drug' })).not.toHaveClass('cds--btn--sm');
    expect(within(panel).getByRole('button', { name: 'Discard drug' }).closest('.afy-dock__actions')).not.toBeNull();
    selectCatalogMedicine(panel, 'Amoxicillin', 'Amoxicillin 500 mg', 'AMOX-500');
    fireEvent.change(within(panel).getByLabelText('Dose (optional)'), { target: { value: '500mg' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Add to basket' }));
    expect(router.post).not.toHaveBeenCalled();
    expect(within(panel).getByRole('heading', { name: 'Prescription medicines (1)' })).toBeInTheDocument();
    fireEvent.click(within(panel).getByRole('button', { name: 'Edit Amoxicillin 500 mg' }));
    selectComboOption(panel, 'Frequency (optional)', 'BID (twice daily)');
    selectComboOption(panel, 'Route (optional)', 'Oral');
    fireEvent.change(within(panel).getByLabelText('Duration (optional)'), { target: { value: '7' } });
    expect(within(panel).getByRole('button', { name: 'Add to basket' })).toBeDisabled();
    fireEvent.change(within(panel).getByLabelText('Unit'), { target: { value: 'day' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Add to basket' }));
    expect(within(panel).getByRole('list', { name: 'Prescription medicines to record' }))
      .toHaveTextContent('Amoxicillin');
    expect(within(panel).getByRole('list', { name: 'Prescription medicines to record' }))
      .toHaveTextContent('BID (twice daily) · 7 days');
    fireEvent.click(within(panel).getByRole('button', { name: 'Edit Amoxicillin 500 mg' }));
    expect(within(panel).getByRole('combobox', { name: 'Frequency (optional)' }))
      .toHaveValue('BID (twice daily)');
    expect(within(panel).getByRole('combobox', { name: 'Route (optional)' })).toHaveValue('Oral');
    expect(within(panel).getByLabelText('Duration (optional)')).toHaveValue(7);
    selectComboOption(panel, 'Frequency (optional)', 'Other — enter manually');
    fireEvent.change(within(panel).getByLabelText('Custom frequency'), {
      target: { value: 'Every other day' },
    });
    selectComboOption(panel, 'Route (optional)', 'Other — enter manually');
    fireEvent.change(within(panel).getByLabelText('Custom route'), {
      target: { value: 'Via feeding tube' },
    });
    selectCatalogMedicine(panel, 'Amoxicillin 250', 'Amoxicillin 250 mg', 'AMOX-250');
    fireEvent.click(within(panel).getByRole('button', { name: 'Add to basket' }));
    fireEvent.click(within(panel).getByRole('button', { name: 'Record and queue 1 medicine' }));
    expect(router.post).toHaveBeenCalledTimes(1);
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/prescriptions/transcribe',
      expect.objectContaining({
        patient_id: 'patient-1',
        source: 'EXTERNAL_PAPER',
        prescriber_name: 'Dr. Asha',
        lines: [expect.objectContaining({
          prescription_id: expect.any(String),
          pharmacy_order_id: expect.any(String),
          inventory_item_id: 'item-amox-250',
          dose: '500mg',
          frequency: 'Every other day',
          duration: '7 days',
          route: 'Via feeding tube',
        })],
      }),
      expect.objectContaining({ preserveScroll: true }),
    );
    expect(within(panel).getByText('1 medicine recorded and queued')).toBeInTheDocument();
    expect(within(panel).getByText(/waiting for pharmacist validation/)).toBeInTheDocument();
    expect(within(panel).getByText(/No stock is deducted until dispensing/)).toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Done' })).toBeEnabled();
    expect(screen.getByRole('dialog', { name: 'External prescription intake' })).toBeInTheDocument();
  });

  it('allows external intake for a selected patient without an encounter', () => {
    render(<PharmacyIndex
      {...baseProps}
      transcribePatient={{ patientId: 'patient-1', patientNumber: 'PAT-00000001', displayName: 'Amina Juma' }}
      transcribeAllergies={[{ substanceName: 'Penicillin', severity: 'MODERATE', reaction: 'Rash' }]}
    />);

    startExternalIntake();
    const panel = screen.getByRole('dialog', { name: 'External prescription intake' });
    expect(within(panel).getByText(/Amina Juma/)).toBeInTheDocument();
    expect(within(panel).getByText('PAT-00000001')).toBeInTheDocument();
    expect(within(panel).queryByText('External prescription · no clinic visit required')).not.toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Change patient' })).toBeInTheDocument();
    expect(within(panel).getByLabelText('Prescriber name')).toBeInTheDocument();
    const basket = within(panel).getByRole('region', { name: 'Prescription drug basket' });
    expect(within(basket).getByRole('heading', { name: 'Prescription medicines (0)' })).toBeInTheDocument();
    expect(within(basket).getByRole('button', { name: 'Add drug' })).toHaveClass('cds--btn--ghost');
    expect(screen.queryByRole('heading', { name: 'Amina Juma' })).not.toBeInTheDocument();
  });

  it('submits all basket medicines together with shared patient and prescriber context', () => {
    vi.mocked(router.post).mockImplementation((_url, _data, options) => {
      (options as { onSuccess?: () => void } | undefined)?.onSuccess?.();
    });
    render(<PharmacyIndex
      {...baseProps}
      transcribePatient={{ patientId: 'patient-1', patientNumber: 'PAT-00000001', displayName: 'Amina Juma' }}
    />);

    startExternalIntake();
    const panel = screen.getByRole('dialog', { name: 'External prescription intake' });

    fireEvent.change(within(panel).getByLabelText('Prescriber name'), { target: { value: 'Dr. Asha' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Add drug' }));
    expect(within(panel).getByRole('button', { name: 'Add to basket' })).toBeDisabled();
    selectCatalogMedicine(panel, 'Amoxicillin', 'Amoxicillin 500 mg', 'AMOX-500');
    fireEvent.change(within(panel).getByLabelText('Dose (optional)'), { target: { value: '500mg' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Add to basket' }));
    expect(within(panel).getByRole('heading', { name: 'Prescription medicines (1)' })).toBeInTheDocument();

    fireEvent.click(within(panel).getByRole('button', { name: 'Add drug' }));
    selectCatalogMedicine(panel, 'Paracetamol', 'Paracetamol 500 mg', 'PARA-500');
    fireEvent.click(within(panel).getByRole('button', { name: 'Add to basket' }));
    expect(within(panel).getByRole('heading', { name: 'Prescription medicines (2)' })).toBeInTheDocument();

    fireEvent.click(within(panel).getByRole('button', { name: 'Record and queue 2 medicines' }));
    expect(router.post).toHaveBeenCalledTimes(1);
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/prescriptions/transcribe',
      expect.objectContaining({
        patient_id: 'patient-1',
        source: 'EXTERNAL_PAPER',
        prescriber_name: 'Dr. Asha',
        lines: [
          expect.objectContaining({
            prescription_id: expect.any(String),
            pharmacy_order_id: expect.any(String),
            inventory_item_id: 'item-amox',
            dose: '500mg',
          }),
          expect.objectContaining({
            prescription_id: expect.any(String),
            pharmacy_order_id: expect.any(String),
            inventory_item_id: 'item-para',
          }),
        ],
      }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('explains that external intake requires order-management access', () => {
    render(<PharmacyIndex
      {...baseProps}
      canManageOrders={false}
      transcribePatient={{ patientId: 'patient-1', patientNumber: 'PAT-00000001', displayName: 'Amina Juma' }}
    />);

    expect(screen.getByRole('alert')).toHaveTextContent('External prescription intake requires pharmacy order-management access');
    expect(screen.queryByRole('button', { name: 'External prescription intake' })).not.toBeInTheDocument();
    expect(router.post).not.toHaveBeenCalled();
  });

  it('preserves line identifiers when retrying a failed external intake', () => {
    let attempt = 0;
    vi.mocked(router.post).mockImplementation((_url, _data, options) => {
      attempt += 1;
      if (attempt === 1) {
        (options as { onError?: (errors: Record<string, string>) => void } | undefined)
          ?.onError?.({ prescription: 'Temporary intake error.' });
      } else {
        (options as { onSuccess?: () => void } | undefined)?.onSuccess?.();
      }
    });

    render(<PharmacyIndex
      {...baseProps}
      transcribePatient={{ patientId: 'patient-1', patientNumber: 'PAT-00000001', displayName: 'Amina Juma' }}
    />);
    startExternalIntake();
    const panel = screen.getByRole('dialog', { name: 'External prescription intake' });
    fireEvent.change(within(panel).getByLabelText('Prescriber name'), { target: { value: 'Dr. Asha' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Add drug' }));
    selectCatalogMedicine(panel, 'Amoxicillin', 'Amoxicillin 500 mg', 'AMOX-500');
    fireEvent.click(within(panel).getByRole('button', { name: 'Add to basket' }));

    fireEvent.click(within(panel).getByRole('button', { name: 'Record and queue 1 medicine' }));
    expect(within(panel).getByText('Temporary intake error.')).toBeInTheDocument();
    fireEvent.click(within(panel).getByRole('button', { name: 'Record and queue 1 medicine' }));

    const firstLines = vi.mocked(router.post).mock.calls[0]?.[1] as { lines: { prescription_id: string; pharmacy_order_id: string }[] };
    const retryLines = vi.mocked(router.post).mock.calls[1]?.[1] as { lines: { prescription_id: string; pharmacy_order_id: string }[] };
    expect(retryLines.lines).toEqual(firstLines.lines);
    expect(within(panel).getByText('1 medicine recorded and queued')).toBeInTheDocument();
  });

  it('removes a drug from the basket before submitting', () => {
    render(<PharmacyIndex
      {...baseProps}
      transcribePatient={{ patientId: 'patient-1', patientNumber: 'PAT-00000001', displayName: 'Amina Juma' }}
    />);

    startExternalIntake();
    const panel = screen.getByRole('dialog', { name: 'External prescription intake' });

    fireEvent.change(within(panel).getByLabelText('Prescriber name'), { target: { value: 'Dr. Asha' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Add drug' }));
    selectCatalogMedicine(panel, 'Amoxicillin', 'Amoxicillin 500 mg', 'AMOX-500');
    fireEvent.click(within(panel).getByRole('button', { name: 'Add to basket' }));
    fireEvent.click(within(panel).getByRole('button', { name: 'Remove Amoxicillin 500 mg' }));

    expect(within(panel).getByRole('heading', { name: 'Prescription medicines (0)' })).toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Record prescription' })).toBeDisabled();
  });
});
