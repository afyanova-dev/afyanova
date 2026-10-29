import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import { PharmacyBoard } from '@/features/pharmacy/components/PharmacyBoard';
import type { PharmacyBoardProps, RxDispensingItem } from '@/features/pharmacy/types/pharmacy';

const state = vi.hoisted(() => ({
  url: '/workspaces/pharmacy?view=orders',
  getJson: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Link: ({ children, href }: { children: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
  router: { visit: vi.fn(), get: vi.fn(), post: vi.fn() },
  usePage: () => ({ url: state.url, props: {} }),
}));

vi.mock('@/lib/http', () => ({
  getJson: state.getJson,
}));

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

function dispensing(overrides: Partial<RxDispensingItem> = {}): RxDispensingItem {
  return {
    dispensingId: 'disp-1',
    dispensingNumber: 'DISP-0001',
    itemCode: 'AMOX-500',
    itemName: 'Amoxicillin 500 mg',
    quantity: '10',
    quantityUnit: 'CAPSULE',
    status: 'COMPLETED',
    pickupStatus: 'READY_FOR_PICKUP',
    reversalId: null,
    receivedAt: '2026-10-02T10:00:00Z',
    ...overrides,
  };
}

const baseProps: PharmacyBoardProps = {
  facilityId: 'facility-1',
  canTranscribe: true,
  canCreatePharmacyPatient: false,
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
    source: 'CLINICAL',
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
  view: 'orders',
  viewPatient: null,
  dispensings: [],
  items: [
    { itemId: 'item-amox', code: 'AMOX-500', name: 'Amoxicillin 500 mg', uom: 'CAPSULE', active: true, itemType: 'MEDICATION', trackBatch: false },
    { itemId: 'item-para', code: 'PARA-500', name: 'Paracetamol 500 mg', uom: 'TABLET', active: true, itemType: 'MEDICATION', trackBatch: false },
  ],
  locations: [
    { locationId: 'loc-1', code: 'MAIN', name: 'Main pharmacy' },
  ],
  selectedItemId: 'item-amox',
  selectedItemCode: 'AMOX-500',
  selectedItemName: 'Amoxicillin 500 mg',
  selectedItemUom: 'CAPSULE',
  availabilityStatus: 'AVAILABLE',
  stockByLocation: [
    { locationId: 'loc-1', batchId: null, batchNumber: null, expiryDate: null, onHand: '100', reserved: '0', available: '100', uom: 'CAPSULE', eligible: true },
  ],
  searchError: null,
  query: null,
  searchResults: null,
  transcribePatient: null,
  transcribeAllergies: [],
  transcribeAllergiesAvailable: true,
};

function expandOrder(): HTMLElement {
  const table = screen.getByRole('table', { name: /Orders/ });
  fireEvent.click(within(table).getByRole('button', { name: 'Expand Amina Juma · PAT-00000001' }));
  return table;
}

describe('pharmacy phase 3 (dispense + pickup)', () => {
  beforeEach(() => {
    state.url = '/workspaces/pharmacy?view=orders';
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

  it('offers Dispense only on dispensable orders with permission', () => {
    const { unmount } = render(<PharmacyBoard {...baseProps} />);
    let table = expandOrder();
    expect(within(table).getByRole('button', { name: 'Dispense' })).toBeInTheDocument();
    unmount();

    for (const status of ['RECEIVED', 'ON_HOLD', 'REJECTED', 'CANCELLED', 'DISPENSED']) {
      const { unmount: u } = render(<PharmacyBoard
        {...baseProps}
        orders={[{ ...baseProps.orders[0]!, status }]}
      />);
      table = expandOrder();
      expect(within(table).queryByRole('button', { name: 'Dispense' })).not.toBeInTheDocument();
      u();
    }

    render(<PharmacyBoard {...baseProps} canDispense={false} />);
    table = expandOrder();
    expect(within(table).queryByRole('button', { name: 'Dispense' })).not.toBeInTheDocument();
  });

  it('shows per-location availability and submits the canonical payload', () => {
    render(<PharmacyBoard {...baseProps} />);
    const table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Dispense' }));

    const panel = screen.getByRole('dialog', { name: 'Dispense ORD-0001' });
    // Availability is per location — nothing is asserted before one is chosen.
    expect(within(panel).getByText('Select a dispensing location to see availability.')).toBeInTheDocument();
    fireEvent.change(within(panel).getByLabelText('Location'), { target: { value: 'loc-1' } });
    expect(within(panel).getByText('Available here: 100 CAPSULE (100 on hand, 0 reserved).')).toBeInTheDocument();
    fireEvent.change(within(panel).getByLabelText('Quantity'), { target: { value: '10' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Dispense' }));

    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/dispensings',
      {
        pharmacy_order_id: 'order-1',
        item_id: 'item-amox',
        location_id: 'loc-1',
        quantity: '10',
        batch_number: null,
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('never presents a failed stock read as zero or success', () => {
    render(<PharmacyBoard {...baseProps} availabilityStatus="FAILED" stockByLocation={[]} />);
    const table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Dispense' }));

    const panel = screen.getByRole('dialog', { name: 'Dispense ORD-0001' });
    fireEvent.change(within(panel).getByLabelText('Location'), { target: { value: 'loc-1' } });
    expect(within(panel).getByText('Stock could not be checked. Try again before dispensing.')).toBeInTheDocument();
    expect(within(panel).queryByText(/No eligible stock/)).not.toBeInTheDocument();
    expect(within(panel).queryByText(/^Available here:/)).not.toBeInTheDocument();
  });

  it('distinguishes zero eligible stock from a not-checked availability', () => {
    const { unmount } = render(<PharmacyBoard
      {...baseProps}
      stockByLocation={[
        { locationId: 'loc-1', batchId: 'b-exp', batchNumber: 'LOT-EXP', expiryDate: '2026-01-01', onHand: '30', reserved: '0', available: '30', uom: 'CAPSULE', eligible: false },
      ]}
    />);
    let table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Dispense' }));
    let panel = screen.getByRole('dialog', { name: 'Dispense ORD-0001' });
    fireEvent.change(within(panel).getByLabelText('Location'), { target: { value: 'loc-1' } });
    expect(within(panel).getByText('No eligible stock at this location — expired batches are excluded.')).toBeInTheDocument();
    unmount();

    render(<PharmacyBoard {...baseProps} availabilityStatus="NOT_CHECKED" stockByLocation={[]} />);
    table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Dispense' }));
    panel = screen.getByRole('dialog', { name: 'Dispense ORD-0001' });
    expect(within(panel).getByText('Availability has not been checked.')).toBeInTheDocument();
  });

  it('shows "Checking stock…" while the selection reloads and refreshes in place', () => {
    render(<PharmacyBoard {...baseProps} />);
    const table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Dispense' }));
    const panel = screen.getByRole('dialog', { name: 'Dispense ORD-0001' });

    // Choosing a different item leaves selectedItemId stale until the server echo.
    fireEvent.change(within(panel).getByLabelText('Item'), { target: { value: 'item-para' } });
    expect(within(panel).getByText('Checking stock…')).toBeInTheDocument();
    expect(router.get).toHaveBeenCalledWith(
      expect.stringContaining('item_id=item-para'),
      {},
      expect.objectContaining({ preserveState: true, replace: true }),
    );
  });

  it('requires an explicit expiry-eligible batch for batch-tracked items', () => {
    render(<PharmacyBoard
      {...baseProps}
      items={[
        ...baseProps.items,
        { itemId: 'item-batch', code: 'BATCH-1', name: 'Batch medicine', uom: 'VIAL', active: true, itemType: 'MEDICATION', trackBatch: true },
      ]}
      selectedItemId="item-batch"
      selectedItemUom="VIAL"
      stockByLocation={[
        { locationId: 'loc-1', batchId: 'b-ok', batchNumber: 'LOT-OK', expiryDate: '2027-06-30', onHand: '50', reserved: '0', available: '50', uom: 'VIAL', eligible: true },
        { locationId: 'loc-1', batchId: 'b-exp', batchNumber: 'LOT-EXP', expiryDate: '2026-01-01', onHand: '30', reserved: '0', available: '30', uom: 'VIAL', eligible: false },
      ]}
    />);
    const table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Dispense' }));
    const panel = screen.getByRole('dialog', { name: 'Dispense ORD-0001' });

    expect(within(panel).getByText('Select a location to choose a batch.')).toBeInTheDocument();
    fireEvent.change(within(panel).getByLabelText('Location'), { target: { value: 'loc-1' } });
    // Only the eligible batch counts toward the location total.
    expect(within(panel).getByText('Available here: 50 VIAL (50 on hand, 0 reserved).')).toBeInTheDocument();

    const batch = within(panel).getByLabelText('Batch');
    expect(within(batch).getByRole('option', { name: /LOT-EXP/ })).toBeDisabled();
    expect(within(batch).getByRole('option', { name: /LOT-OK/ })).toBeInTheDocument();

    fireEvent.change(within(panel).getByLabelText('Quantity'), { target: { value: '5' } });
    // No batch chosen yet → the primary action stays disabled.
    expect(within(panel).getByRole('button', { name: 'Dispense' })).toBeDisabled();

    fireEvent.change(batch, { target: { value: 'LOT-OK' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Dispense' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/dispensings',
      {
        pharmacy_order_id: 'order-1',
        item_id: 'item-batch',
        location_id: 'loc-1',
        quantity: '5',
        batch_number: 'LOT-OK',
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('fixes the item for external paper prescriptions', () => {
    render(<PharmacyBoard
      {...baseProps}
      prescriptions={[{ ...baseProps.prescriptions[0]!, source: 'EXTERNAL_PAPER' }]}
    />);
    const table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Dispense' }));

    const panel = screen.getByRole('dialog', { name: 'Dispense ORD-0001' });
    expect(within(panel).getByText('Paper prescription — item is fixed to the transcribed medicine.')).toBeInTheDocument();
    expect(within(panel).getByLabelText('Item')).toHaveAttribute('readonly');
  });

  it('reverses a dispensing with a fixed reason code', () => {
    render(<PharmacyBoard
      {...baseProps}
      orders={[{ ...baseProps.orders[0]!, status: 'PARTIALLY_DISPENSED', dispensings: [dispensing()] }]}
    />);
    const table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Reverse' }));

    const dialog = screen.getByRole('dialog', { name: 'Reverse dispensing' });
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'WRONG_ITEM' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reverse dispensing' }));

    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/dispensings/disp-1/reverse',
      { pharmacy_order_id: 'order-1', reason_code: 'WRONG_ITEM', reason_note: null },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('hides Reverse without permission, on picked-up handovers, or terminal orders', () => {
    const { unmount } = render(<PharmacyBoard {...baseProps} canReverse={false} orders={[{ ...baseProps.orders[0]!, status: 'PARTIALLY_DISPENSED', dispensings: [dispensing()] }]} />);
    let table = expandOrder();
    expect(within(table).queryByRole('button', { name: 'Reverse' })).not.toBeInTheDocument();
    unmount();

    render(<PharmacyBoard {...baseProps} orders={[{ ...baseProps.orders[0]!, status: 'PARTIALLY_DISPENSED', dispensings: [dispensing({ pickupStatus: 'PICKED_UP' })] }]} />);
    table = expandOrder();
    expect(within(table).queryByRole('button', { name: 'Reverse' })).not.toBeInTheDocument();
  });

  it('hides Reverse for an already reversed dispensing when another can still be reversed', () => {
    render(<PharmacyBoard
      {...baseProps}
      orders={[{
        ...baseProps.orders[0]!,
        status: 'PARTIALLY_DISPENSED',
        dispensings: [
          dispensing({ reversalId: 'reversal-1' }),
          dispensing({
            dispensingId: 'disp-2',
            dispensingNumber: 'DISP-0002',
            itemName: 'Paracetamol 500 mg',
            reversalId: null,
          }),
        ],
      }]}
    />);
    const table = expandOrder();

    expect(within(table).getAllByRole('button', { name: 'Reverse' })).toHaveLength(1);
    expect(within(table).getByText(/DISP-0001/)).toBeInTheDocument();
    expect(within(table).getByText(/DISP-0002/)).toBeInTheDocument();
  });

  it('records a pickup with recipient codes and surfaces the billing gate', () => {
    render(<PharmacyBoard
      {...baseProps}
      orders={[{ ...baseProps.orders[0]!, status: 'DISPENSED', dispensings: [dispensing()] }]}
    />);
    const table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Record pickup' }));

    const panel = screen.getByRole('dialog', { name: 'Record pickup DISP-0001' });
    expect(within(panel).getByText(/blocked until the billing clearance/)).toBeInTheDocument();
    fireEvent.change(within(panel).getByLabelText('Recipient'), { target: { value: 'PATIENT' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Record pickup' }));

    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/pickups',
      {
        pharmacy_order_id: 'order-1',
        dispensing_id: 'disp-1',
        recipient_type: 'PATIENT',
        recipient_name: null,
        verification_note: null,
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('marks not-collected with an outcome code', () => {
    render(<PharmacyBoard
      {...baseProps}
      orders={[{ ...baseProps.orders[0]!, status: 'DISPENSED', dispensings: [dispensing()] }]}
    />);
    const table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Not collected' }));
    const dialog = screen.getByRole('dialog', { name: 'Mark not collected' });
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'PATIENT_NO_SHOW' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Mark not collected' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/pickups/not-collected',
      { pharmacy_order_id: 'order-1', dispensing_id: 'disp-1', reason_code: 'PATIENT_NO_SHOW', note: null },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('cancels a pickup with an outcome code', () => {
    render(<PharmacyBoard
      {...baseProps}
      orders={[{ ...baseProps.orders[0]!, status: 'DISPENSED', dispensings: [dispensing()] }]}
    />);
    const table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Cancel pickup' }));
    const dialog = screen.getByRole('dialog', { name: 'Cancel pickup' });
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'DUPLICATE' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel pickup' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/pharmacy/pickups/cancel',
      { pharmacy_order_id: 'order-1', dispensing_id: 'disp-1', reason_code: 'DUPLICATE', note: null },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('hides pickup actions without permission or on terminal pickups', () => {
    render(<PharmacyBoard
      {...baseProps}
      canRecordPickup={false}
      orders={[{ ...baseProps.orders[0]!, status: 'DISPENSED', dispensings: [dispensing()] }]}
    />);
    const table = expandOrder();
    expect(within(table).queryByRole('button', { name: 'Record pickup' })).not.toBeInTheDocument();
    expect(within(table).queryByRole('button', { name: 'Not collected' })).not.toBeInTheDocument();
    expect(within(table).queryByRole('button', { name: 'Cancel pickup' })).not.toBeInTheDocument();
  });

  it('shows a one-line allergy warning in the dispense panel when allergies are known', () => {
    render(<PharmacyBoard
      {...baseProps}
      selectedPatientId="patient-1"
      selectedPatientAllergies={[{ substanceName: 'Penicillin', severity: 'MODERATE', reaction: 'Rash' }]}
    />);
    // Row auto-expands for the selected patient — details are already visible.
    const table = screen.getByRole('table', { name: /Orders/ });
    fireEvent.click(within(table).getByRole('button', { name: 'Dispense' }));

    const panel = screen.getByRole('dialog', { name: 'Dispense ORD-0001' });
    expect(within(panel).getByText('Allergies: Penicillin (MODERATE, Rash)')).toBeInTheDocument();
  });

  it('shows a muted line when allergy data is loaded and empty, nothing when not loaded', () => {
    const { unmount } = render(<PharmacyBoard
      {...baseProps}
      selectedPatientId="patient-1"
      selectedPatientAllergies={[]}
    />);
    let table = screen.getByRole('table', { name: /Orders/ });
    fireEvent.click(within(table).getByRole('button', { name: 'Dispense' }));
    let panel = screen.getByRole('dialog', { name: 'Dispense ORD-0001' });
    expect(within(panel).getByText('No recorded allergies.')).toBeInTheDocument();
    expect(within(panel).queryByText(/Allergies:/)).not.toBeInTheDocument();
    unmount();

    render(<PharmacyBoard {...baseProps} />);
    table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Dispense' }));
    panel = screen.getByRole('dialog', { name: 'Dispense ORD-0001' });
    expect(within(panel).queryByText(/Allergies:/)).not.toBeInTheDocument();
    expect(within(panel).queryByText('No recorded allergies.')).not.toBeInTheDocument();
  });

  it('loads the allergy context when opening the dispense panel', () => {
    render(<PharmacyBoard {...baseProps} />);
    const table = expandOrder();
    fireEvent.click(within(table).getByRole('button', { name: 'Dispense' }));

    expect(router.get).toHaveBeenCalledWith(
      expect.stringContaining('order_id=order-1'),
      {},
      expect.objectContaining({ preserveState: true, replace: true }),
    );
  });

  it('shows the dispense date on each dispensing row', () => {
    render(<PharmacyBoard
      {...baseProps}
      orders={[{ ...baseProps.orders[0]!, status: 'DISPENSED', dispensings: [dispensing({ receivedAt: '2026-10-02T10:00:00Z' })] }]}
    />);
    const table = expandOrder();
    expect(within(table).getByText(/Oct 02/)).toBeInTheDocument();
  });
});
