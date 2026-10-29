import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  InventoryWorkspace,
  type InventoryItem,
  type InventoryWorkspaceProps,
  type StockCount,
  type StockCountSheet,
  type StockLocation,
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
  posted: [] as Array<{ url: string; data: Record<string, unknown>; only?: string[] }>,
  visited: [] as Array<{ url: string; params: Record<string, unknown> }>,
}));

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');

  return {
    router: {
      get: vi.fn((url: string, params: Record<string, unknown>) => {
        formState.visited.push({ url, params });
      }),
      post: vi.fn((
        url: string,
        data: Record<string, unknown>,
        options?: { onSuccess?: () => void; onFinish?: () => void; only?: string[] },
      ) => {
        formState.posted.push({ url, data, only: options?.only });
        options?.onSuccess?.();
        options?.onFinish?.();
      }),
      patch: vi.fn(),
    },
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
        post: vi.fn((url: string, options?: { onSuccess?: () => void; only?: string[] }) => {
          formState.posted.push({ url, data: data as Record<string, unknown>, only: options?.only });
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
  item({ id: 'glv', code: 'GLV-1', name: 'Examination gloves', unitOfMeasure: 'PAIR' }),
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

const counts: StockCount[] = [
  {
    countId: 'count-1',
    countNumber: 'CNT-00000001',
    facilityId: 'facility-1',
    locationId: 'loc-main',
    locationCode: 'MAIN',
    locationName: 'Main Store',
    status: 'IN_PROGRESS',
    notes: 'Monthly count.',
    startedByUserId: 'user-9',
    startedAt: '2026-10-20T09:00:00+00:00',
    closedAt: null,
    lineCount: 2,
    countedCount: 1,
    varianceCount: 1,
  },
  {
    countId: 'count-2',
    countNumber: 'CNT-00000002',
    facilityId: 'facility-1',
    locationId: 'loc-pharmacy',
    locationCode: 'PHARMACY',
    locationName: 'Pharmacy Counter',
    status: 'COMPLETED',
    notes: null,
    startedByUserId: 'user-9',
    startedAt: '2026-10-19T09:00:00+00:00',
    closedAt: '2026-10-19T11:00:00+00:00',
    lineCount: 1,
    countedCount: 1,
    varianceCount: 0,
  },
];

const sheet: StockCountSheet = {
  count: counts[0]!,
  lines: [
    {
      lineId: 'line-glv',
      itemId: 'glv',
      itemCode: 'GLV-1',
      itemName: 'Examination gloves',
      uom: 'PAIR',
      packUnit: 'BOX',
      packSize: '100.0000',
      batchId: null,
      batchNumber: null,
      systemQty: '12.0000',
      countedQty: null,
      variance: null,
      isControlled: false,
      adjustmentId: null,
      adjustmentStatus: null,
      varianceSkippedReason: null,
    },
    {
      lineId: 'line-para',
      itemId: 'para',
      itemCode: 'PARA-500',
      itemName: 'Paracetamol 500mg',
      uom: 'TABLET',
      packUnit: 'BOX',
      packSize: '100.0000',
      batchId: null,
      batchNumber: null,
      systemQty: '100.0000',
      countedQty: '96.0000',
      variance: '-4.0000',
      isControlled: false,
      adjustmentId: null,
      adjustmentStatus: null,
      varianceSkippedReason: null,
    },
  ],
};

const baseProps = {
  items,
  locations,
  locationTypes: [],
  selectedItemId: null,
  selectedLocationId: null,
  balances: [],
  canReceive: false,
  canMatchInvoices: false,
  canTransfer: false,
  canManageCounts: true,
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
  unitsOfMeasure: ['TABLET', 'PAIR', 'CAPSULE'],
  errors: {},
  status: null,
};

function renderCounts(overrides: Partial<InventoryWorkspaceProps> = {}): void {
  render(
    <InventoryWorkspace
      {...baseProps}
      currentSection="counts"
      stockCounts={counts}
      stockCountSheet={sheet}
      {...overrides}
    />,
  );
}

describe('Inventory stock counts', () => {
  beforeEach(() => {
    formState.posted.length = 0;
    formState.visited.length = 0;
    vi.mocked(router.post).mockClear();
    vi.mocked(router.get).mockClear();
  });

  it('lists counts with their location, status and progress', () => {
    renderCounts();

    expect(screen.getByRole('region', { name: 'Stock counts' })).toBeInTheDocument();
    const table = screen.getByRole('table', { name: 'Stock counts (2 of 2)' });

    expect(within(table).getByText(/CNT-00000001/)).toBeInTheDocument();
    expect(within(table).getByText('MAIN — Main Store')).toBeInTheDocument();
    expect(within(table).getByText('In progress')).toBeInTheDocument();
    expect(within(table).getByText('1 of 2 lines counted')).toBeInTheDocument();
    expect(within(table).getByText('Completed')).toBeInTheDocument();
  });

  it('starts a count at the chosen location', () => {
    renderCounts();

    fireEvent.click(screen.getByRole('button', { name: 'Start a count' }));

    const dialog = screen.getByRole('dialog', { name: 'Start a stock count' });
    expect(within(dialog).getByText('The sheet is a snapshot')).toBeInTheDocument();

    fireEvent.change(within(dialog).getByLabelText('Location to count'), {
      target: { value: 'loc-main' },
    });
    fireEvent.change(within(dialog).getByLabelText('Notes (optional)'), {
      target: { value: 'October count.' },
    });

    fireEvent.click(within(dialog).getByRole('button', { name: 'Start count' }));

    expect(formState.posted).toHaveLength(1);
    expect(formState.posted[0]!.url).toBe('/workspaces/inventory/stock-counts');
    expect(formState.posted[0]!.data.location_id).toBe('loc-main');
    expect(formState.posted[0]!.data.notes).toBe('October count.');
    expect(formState.posted[0]!.only).toEqual(['stockCounts', 'stockCountSheet', 'status', 'errors']);
  });

  it('opens one count sheet as a partial reload', () => {
    renderCounts();

    fireEvent.click(screen.getByRole('button', { name: 'Continue count' }));

    expect(formState.visited).toHaveLength(1);
    expect(formState.visited[0]!.url).toBe('/workspaces/inventory');
    expect(formState.visited[0]!.params).toMatchObject({
      section: 'counts',
      count_id: 'count-1',
    });
  });

  it('shows the system quantity, the counted quantity and the difference', () => {
    renderCounts();

    fireEvent.click(screen.getByRole('button', { name: 'Continue count' }));

    const dialog = screen.getByRole('dialog', { name: 'Count CNT-00000001' });
    const table = within(dialog).getByRole('table', { name: 'Count sheet' });

    expect(within(table).getByText('100 TABLET')).toBeInTheDocument();
    expect(within(table).getByText('-4')).toBeInTheDocument();
    expect(within(table).getByText('12 PAIR')).toBeInTheDocument();

    // A blank box means "not counted" — it is not a false shortage.
    expect(within(table).getAllByText('—').length).toBeGreaterThan(0);

    expect(within(dialog).getByText('1 of 2 lines counted')).toBeInTheDocument();
    expect(within(dialog).getByText('1 with a difference')).toBeInTheDocument();
  });

  it('updates the difference as the counter types and saves every line', () => {
    renderCounts();

    fireEvent.click(screen.getByRole('button', { name: 'Continue count' }));

    const dialog = screen.getByRole('dialog', { name: 'Count CNT-00000001' });

    fireEvent.change(within(dialog).getByLabelText('Counted quantity for PARA-500'), {
      target: { value: '90' },
    });

    // 90 found against 100 on the sheet is a shortfall of 10.
    expect(within(dialog).getByText('-10')).toBeInTheDocument();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Save counts' }));

    expect(formState.posted).toHaveLength(1);
    expect(formState.posted[0]!.url).toBe('/workspaces/inventory/stock-counts/count-1/counts');

    const countsPosted = formState.posted[0]!.data.counts as Record<string, string | null>;
    expect(countsPosted['line-para']).toBe('90');
    // The untouched glove line stays uncounted, never silently zero.
    expect(countsPosted['line-glv']).toBeNull();
    expect(vi.mocked(router.post)).toHaveBeenCalledWith(
      '/workspaces/inventory/stock-counts/count-1/counts',
      expect.any(Object),
      expect.objectContaining({ only: ['stockCounts', 'stockCountSheet', 'status', 'errors'] }),
    );
  });

  it('adds an item the system did not list, naming its batch', () => {
    renderCounts();

    fireEvent.click(screen.getByRole('button', { name: 'Continue count' }));

    const dialog = screen.getByRole('dialog', { name: 'Count CNT-00000001' });

    const itemCombo = within(dialog).getByRole('combobox', { name: 'Found something not on the sheet?' });
    fireEvent.change(itemCombo, { target: { value: 'AMOX-500' } });
    fireEvent.click(within(dialog).getByRole('option', { name: /AMOX-500/ }));
    fireEvent.change(within(dialog).getByLabelText('Batch number'), {
      target: { value: 'B-1' },
    });

    fireEvent.click(within(dialog).getByRole('button', { name: 'Add to sheet' }));

    expect(formState.posted).toHaveLength(1);
    expect(formState.posted[0]!.url).toBe('/workspaces/inventory/stock-counts/count-1/lines');
    expect(formState.posted[0]!.data.item_id).toBe('amox');
    expect(formState.posted[0]!.data.batch_number).toBe('B-1');
  });

  it('keeps a closed count read only', () => {
    renderCounts({
      stockCountSheet: {
        count: counts[1]!,
        lines: [{
          lineId: 'line-para',
          itemId: 'para',
          itemCode: 'PARA-500',
          itemName: 'Paracetamol 500mg',
          uom: 'TABLET',
          packUnit: 'BOX',
          packSize: '100.0000',
          batchId: null,
          batchNumber: null,
          systemQty: '100.0000',
          countedQty: '100.0000',
          variance: '0.0000',
          isControlled: false,
          adjustmentId: null,
          adjustmentStatus: null,
          varianceSkippedReason: null,
        }],
      },
    });

    fireEvent.click(screen.getByRole('button', { name: 'View sheet' }));

    const dialog = screen.getByRole('dialog', { name: 'Count CNT-00000002' });

    expect(within(dialog).getByText('This count is closed')).toBeInTheDocument();
    expect(within(dialog).queryByRole('button', { name: 'Save counts' })).toBeNull();
    expect(within(dialog).queryByLabelText('Counted quantity for PARA-500')).toBeNull();
  });

  it('hides counting controls without the count permission', () => {
    renderCounts({ canManageCounts: false });

    expect(screen.queryByRole('button', { name: 'Start a count' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Continue count' }));

    const dialog = screen.getByRole('dialog', { name: 'Count CNT-00000001' });
    expect(within(dialog).getByText('Read only')).toBeInTheDocument();
    expect(within(dialog).queryByRole('button', { name: 'Save counts' })).toBeNull();
  });

  it('asks once before discarding a count', () => {
    renderCounts();

    fireEvent.click(screen.getByRole('button', { name: 'Continue count' }));
    const dialog = screen.getByRole('dialog', { name: 'Count CNT-00000001' });

    fireEvent.click(within(dialog).getByRole('button', { name: 'Discard' }));

    expect(within(dialog).getByText('Discard this count?')).toBeInTheDocument();
    expect(formState.posted).toHaveLength(0);

    fireEvent.click(within(dialog).getByRole('button', { name: 'Yes, discard' }));

    expect(formState.posted).toHaveLength(1);
    expect(formState.posted[0]!.url).toBe('/workspaces/inventory/stock-counts/count-1/close');
    expect(formState.posted[0]!.data.decision).toBe('DISCARD');
    expect(formState.posted[0]!.only).toEqual(['stockCounts', 'stockCountSheet', 'status', 'errors']);
  });

  it('requires a witness for a controlled-stock shortfall and submits unsaved counts', () => {
    const controlledSheet: StockCountSheet = {
      count: counts[0]!,
      lines: [{
        ...sheet.lines[1]!,
        isControlled: true,
        countedQty: null,
        variance: null,
      }],
    };
    renderCounts({ stockCountSheet: controlledSheet });
    fireEvent.click(screen.getByRole('button', { name: 'Continue count' }));

    const dialog = screen.getByRole('dialog', { name: 'Count CNT-00000001' });
    fireEvent.change(within(dialog).getByLabelText('Counted quantity for PARA-500'), {
      target: { value: '90' },
    });
    expect(within(dialog).getByRole('button', { name: 'Complete count' })).toBeDisabled();

    fireEvent.change(within(dialog).getByLabelText('Witness name'), {
      target: { value: 'Alex Witness' },
    });
    expect(within(dialog).getByRole('button', { name: 'Complete count' })).toBeEnabled();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Complete count' }));

    expect(formState.posted[0]!.data).toMatchObject({
      decision: 'COMPLETE',
      counts: { 'line-para': '90' },
      witness_name: 'Alex Witness',
    });
    expect(vi.mocked(router.post)).toHaveBeenCalledWith(
      '/workspaces/inventory/stock-counts/count-1/close',
      expect.any(Object),
      expect.objectContaining({ only: ['stockCounts', 'stockCountSheet', 'status', 'errors'] }),
    );
  });

  it('uses the facility context note in general mode', () => {
    renderCounts({ contextMode: 'general', stockCounts: [], stockCountSheet: null });

    expect(within(screen.getByRole('region', { name: 'Stock counts' })).getByText('Select a facility')).toBeInTheDocument();
    expect(screen.queryByRole('table', { name: /Stock counts/ })).toBeNull();
  });
});
