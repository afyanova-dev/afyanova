import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  InventoryWorkspace,
  type InventoryItem,
  type InventoryWorkspaceProps,
  type StockLocation,
  type StockTransfer,
  type TransferSourceItem,
} from '@/features/inventory';

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

const formState = vi.hoisted(() => ({
  posted: [] as Array<{ url: string; data: Record<string, unknown> }>,
}));

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');

  return {
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
    useForm: (initial: Record<string, unknown>) => {
      const [data, setDataState] = React.useState(initial);

      return {
        data,
        errors: {},
        processing: false,
        setData: React.useCallback((keyOrData: unknown, value?: unknown) => {
          setDataState((previous: Record<string, unknown>) => (
            typeof keyOrData === 'object' && keyOrData !== null
              ? { ...previous, ...(keyOrData as Record<string, unknown>) }
              : { ...previous, [keyOrData as string]: value }
          ));
        }, []),
        clearErrors: vi.fn(),
        reset: vi.fn(),
        post: vi.fn((url: string, options?: { onSuccess?: () => void }) => {
          formState.posted.push({ url, data: data as Record<string, unknown> });
          options?.onSuccess?.();
        }),
      };
    },
  };
});

function item(overrides: Partial<InventoryItem> & Pick<InventoryItem, 'id' | 'code' | 'name'>): InventoryItem {
  return {
    category: null,
    description: null,
    itemType: 'MEDICATION',
    unitOfMeasure: 'TABLET',
    packUnit: 'BOX',
    packSize: '100.0000',
    tracksBatch: false,
    tracksExpiry: false,
    tracksSerial: false,
    isControlled: false,
    active: true,
    ...overrides,
  };
}

const items: InventoryItem[] = [
  item({ id: 'para', code: 'PARA-500', name: 'Paracetamol 500mg' }),
  item({
    id: 'amox',
    code: 'AMOX-500',
    name: 'Amoxicillin 500mg',
    unitOfMeasure: 'CAPSULE',
    tracksBatch: true,
    tracksExpiry: true,
  }),
];

const locations: StockLocation[] = [
  { id: 'loc-main', code: 'MAIN', name: 'Main Store', locationType: 'STORE', active: true },
  { id: 'loc-pharmacy', code: 'PHARMACY', name: 'Pharmacy Counter', locationType: 'DISPENSARY', active: true },
];

const transfers: StockTransfer[] = [
  {
    transferId: 'transfer-1',
    transferNumber: 'TRF-00000001',
    itemId: 'para',
    itemCode: 'PARA-500',
    itemName: 'Paracetamol 500mg',
    fromLocationId: 'loc-main',
    fromLocationCode: 'MAIN',
    toLocationId: 'loc-pharmacy',
    toLocationCode: 'PHARMACY',
    batchId: null,
    batchNumber: null,
    quantity: '30.0000',
    uom: 'TABLET',
    reasonText: 'Weekly refill of the pharmacy counter.',
    movedByUserId: 'user-9',
    movedAt: '2026-10-19T07:30:00+00:00',
  },
];

const source: TransferSourceItem[] = [
  {
    itemId: 'para',
    itemCode: 'PARA-500',
    itemName: 'Paracetamol 500mg',
    onHand: '70.0000',
    reserved: '0.0000',
    available: '70.0000',
    uom: 'TABLET',
    batches: [
      {
        batchId: null,
        batchNumber: null,
        expiryDate: null,
        onHand: '70.0000',
        reserved: '0.0000',
        available: '70.0000',
        uom: 'TABLET',
        eligible: true,
      },
    ],
  },
  {
    itemId: 'amox',
    itemCode: 'AMOX-500',
    itemName: 'Amoxicillin 500mg',
    onHand: '15.0000',
    reserved: '0.0000',
    available: '15.0000',
    uom: 'CAPSULE',
    batches: [
      {
        batchId: 'batch-fresh',
        batchNumber: 'B-FRESH',
        expiryDate: '2027-06-30',
        onHand: '10.0000',
        reserved: '0.0000',
        available: '10.0000',
        uom: 'CAPSULE',
        eligible: true,
      },
      {
        batchId: 'batch-old',
        batchNumber: 'B-OLD',
        expiryDate: '2026-01-31',
        onHand: '5.0000',
        reserved: '0.0000',
        available: '5.0000',
        uom: 'CAPSULE',
        eligible: false,
      },
    ],
  },
];

const baseProps = {
  items,
  locations,
  locationTypes: [],
  selectedItemId: null,
  selectedLocationId: null,
  balances: [],
  canReceive: false,
  canMatchInvoices: false,
  canTransfer: true,
  canRequestAdjustment: false,
  canApproveAdjustments: false,
  currentUserId: 'user-9',
  canManageItems: false,
  canManageLevels: false,
  canManageLocations: false,
  canManageLocationTypes: false,
  contextMode: 'facility' as const,
  itemTypes: ['MEDICATION'],
  itemCategories: ['ANALGESIC'],
  unitsOfMeasure: ['TABLET', 'CAPSULE'],
  errors: {},
  status: null,
};

function renderTransfers(overrides: Partial<InventoryWorkspaceProps> = {}): void {
  render(
    <InventoryWorkspace
      {...baseProps}
      currentSection="transfers"
      transfers={transfers}
      transferSource={source}
      transferFromLocationId="loc-main"
      {...overrides}
    />,
  );
}

describe('Inventory stock transfers', () => {
  beforeEach(() => {
    formState.posted.length = 0;
    vi.mocked(router.post).mockClear();
    vi.mocked(router.get).mockClear();
  });

  it('lists transfers with the route, quantity and reason', () => {
    renderTransfers();

    expect(screen.getByRole('region', { name: 'Transfers' })).toBeInTheDocument();
    const table = screen.getByRole('table', { name: 'Transfers (1 of 1)' });
    expect(within(table).getByText(/TRF-00000001/)).toBeInTheDocument();
    expect(within(table).getByText('PARA-500 — Paracetamol 500mg')).toBeInTheDocument();
    expect(within(table).getByText('MAIN → PHARMACY')).toBeInTheDocument();
    expect(within(table).getByText('30.0000 TABLET')).toBeInTheDocument();
    expect(within(table).getByText('Weekly refill of the pharmacy counter.')).toBeInTheDocument();
  });

  it('searches by item, transfer number or location', () => {
    renderTransfers();

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search transfers' }), {
      target: { value: 'nothing-matches' },
    });

    expect(screen.getByText('No transfer matches this search.')).toBeInTheDocument();

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search transfers' }), {
      target: { value: 'pharmacy' },
    });

    expect(screen.getByRole('row', { name: /TRF-00000001/ })).toBeInTheDocument();
  });

  it('moves stock in base units and shows the pack as a counting help', () => {
    renderTransfers();

    fireEvent.click(screen.getByRole('button', { name: 'Move stock' }));

    const dialog = screen.getByRole('dialog', { name: 'Move stock' });
    expect(within(dialog).getByText('One move, two ledger entries')).toBeInTheDocument();

    const itemCombo = within(dialog).getByRole('combobox', { name: 'Item to move' });
    fireEvent.change(itemCombo, { target: { value: 'PARA-500' } });
    fireEvent.click(within(dialog).getByRole('option', { name: /PARA-500/ }));

    expect(within(dialog).getByText('70.0000 TABLET available. 1 BOX = 100.0000 TABLET.')).toBeInTheDocument();

    fireEvent.change(within(dialog).getByLabelText('Quantity (TABLET)'), { target: { value: '25' } });
    expect(within(dialog).getByText(/25 TABLET ≈ 0\.25 BOX \(1 BOX = 100\.0000 TABLET\)\./)).toBeInTheDocument();

    fireEvent.change(within(dialog).getByLabelText('Move to'), { target: { value: 'loc-pharmacy' } });
    fireEvent.change(within(dialog).getByLabelText('Reason (required if backdated more than 24 hours)'), {
      target: { value: 'Weekly refill.' },
    });

    fireEvent.click(within(dialog).getByRole('button', { name: 'Move stock' }));

    expect(formState.posted).toHaveLength(1);
    const posted = formState.posted[0];
    if (posted === undefined) throw new Error('Transfer was not submitted');
    expect(posted.url).toBe('/workspaces/inventory/transfers');
    expect(posted.data).toMatchObject({
      from_location_id: 'loc-main',
      to_location_id: 'loc-pharmacy',
      item_id: 'para',
      quantity: '25',
      batch_number: '',
      reason: 'Weekly refill.',
    });
  });

  it('selects a batch for a batch-tracked item and refuses an expired one', () => {
    renderTransfers();

    fireEvent.click(screen.getByRole('button', { name: 'Move stock' }));
    const dialog = screen.getByRole('dialog', { name: 'Move stock' });

    const itemCombo = within(dialog).getByRole('combobox', { name: 'Item to move' });
    fireEvent.change(itemCombo, { target: { value: 'AMOX-500' } });
    fireEvent.click(within(dialog).getByRole('option', { name: /AMOX-500/ }));

    // The first usable batch is chosen automatically.
    expect((within(dialog).getByLabelText('Batch') as HTMLSelectElement).value).toBe('B-FRESH');

    fireEvent.change(within(dialog).getByLabelText('Batch'), { target: { value: 'B-OLD' } });

    expect(within(dialog).getByText('Expired batch')).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Move stock' })).toBeDisabled();
  });

  it('refuses a quantity larger than the source has', () => {
    renderTransfers();

    fireEvent.click(screen.getByRole('button', { name: 'Move stock' }));
    const dialog = screen.getByRole('dialog', { name: 'Move stock' });

    const itemCombo = within(dialog).getByRole('combobox', { name: 'Item to move' });
    fireEvent.change(itemCombo, { target: { value: 'PARA-500' } });
    fireEvent.click(within(dialog).getByRole('option', { name: /PARA-500/ }));
    fireEvent.change(within(dialog).getByLabelText('Move to'), { target: { value: 'loc-pharmacy' } });
    fireEvent.change(within(dialog).getByLabelText('Quantity (TABLET)'), { target: { value: '71' } });

    expect(within(dialog).getByText('Only 70.0000 TABLET is available here.')).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Move stock' })).toBeDisabled();
  });

  it('reloads the source stock when the source location changes', () => {
    renderTransfers();

    fireEvent.click(screen.getByRole('button', { name: 'Move stock' }));
    const dialog = screen.getByRole('dialog', { name: 'Move stock' });

    fireEvent.change(within(dialog).getByLabelText('Stock is in'), { target: { value: 'loc-pharmacy' } });

    expect(router.get).toHaveBeenCalledWith(
      '/workspaces/inventory',
      { section: 'transfers', transfer_from: 'loc-pharmacy' },
      expect.objectContaining({
        preserveScroll: true,
        preserveState: true,
        only: ['transferSource', 'transferFromLocationId'],
      }),
    );
  });

  it('is read-only without the transfer permission', () => {
    renderTransfers({ canTransfer: false });

    expect(screen.queryByRole('button', { name: 'Move stock' })).not.toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Transfers (1 of 1)' })).toBeInTheDocument();
  });

  it('explains that transfers happen inside one facility in General context', () => {
    renderTransfers({ contextMode: 'general', transfers: [], transferSource: [], transferFromLocationId: null });

    const region = screen.getByRole('region', { name: 'Transfers' });
    expect(within(region).getByText('Select a facility')).toBeInTheDocument();
    expect(screen.queryByRole('table', { name: /Transfers/ })).not.toBeInTheDocument();
  });
});
