import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  InventoryWorkspace,
  type InventoryItem,
  type InventoryWorkspaceProps,
  type ItemLevel,
  type LowStockItem,
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
  item({ id: 'item-out', code: 'OUT-1', name: 'Out item' }),
  item({ id: 'item-low', code: 'LOW-1', name: 'Low item', unitOfMeasure: 'CAPSULE' }),
  item({ id: 'item-ok', code: 'OK-1', name: 'Healthy item' }),
];

const lowStock: LowStockItem[] = [
  {
    itemId: 'item-out',
    itemCode: 'OUT-1',
    itemName: 'Out item',
    uom: 'TABLET',
    packUnit: 'BOX',
    packSize: '100.0000',
    minLevel: '50.0000',
    onHand: '0.0000',
    expired: '0.0000',
    shortfall: '50.0000',
    outOfStock: true,
  },
  {
    itemId: 'item-low',
    itemCode: 'LOW-1',
    itemName: 'Low item',
    uom: 'CAPSULE',
    packUnit: 'BOX',
    packSize: '100.0000',
    minLevel: '25.0000',
    onHand: '10.0000',
    expired: '6.0000',
    shortfall: '15.0000',
    outOfStock: false,
  },
];

const levels: ItemLevel[] = [
  { itemId: 'item-low', minLevel: '25.0000' },
  { itemId: 'item-ok', minLevel: '5.0000' },
];

const baseProps = {
  items,
  locations: [],
  locationTypes: [],
  selectedItemId: null,
  selectedLocationId: null,
  balances: [],
  canReceive: false,
  canMatchInvoices: false,
  canRequestAdjustment: false,
  canApproveAdjustments: false,
  currentUserId: 'user-9',
  canManageItems: false,
  canManageLevels: true,
  canManageLocations: false,
  canManageLocationTypes: false,
  contextMode: 'facility' as const,
  itemTypes: ['MEDICATION'],
  itemCategories: ['ANALGESIC'],
  unitsOfMeasure: ['TABLET', 'CAPSULE'],
  errors: {},
  status: null,
};

function renderLowStock(overrides: Partial<InventoryWorkspaceProps> = {}): void {
  render(
    <InventoryWorkspace
      {...baseProps}
      currentSection="low-stock"
      lowStock={lowStock}
      itemLevels={levels}
      {...overrides}
    />,
  );
}

describe('Inventory running-low worklist', () => {
  beforeEach(() => {
    formState.posted.length = 0;
    vi.mocked(router.post).mockClear();
  });

  it('lists tracked items with on-hand, minimum and what to order', () => {
    renderLowStock();

    expect(screen.getByRole('region', { name: 'Running low' })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Running low (2 of 2)' })).toBeInTheDocument();

    const summary = screen.getByRole('region', { name: 'Low-stock summary' });
    expect(within(summary).getByText('Out of stock')).toBeInTheDocument();
    expect(within(summary).getByText('1')).toBeInTheDocument();
    expect(within(summary).getByText('At or below minimum')).toBeInTheDocument();
    expect(within(summary).getAllByText('2')).toHaveLength(2);
    expect(within(summary).getByText('Items tracked')).toBeInTheDocument();

    const outRow = screen.getByRole('row', { name: /OUT-1/ });
    expect(within(outRow).getByText('OUT-1 — Out item')).toBeInTheDocument();
    expect(within(outRow).getByText('0.0000 TABLET')).toBeInTheDocument();
    expect(within(outRow).getAllByText('50.0000 TABLET')).toHaveLength(2); // minimum and to order
    expect(within(outRow).getByText('Out of stock')).toBeInTheDocument();

    const lowRow = screen.getByRole('row', { name: /LOW-1/ });
    expect(within(lowRow).getByText('10.0000 CAPSULE')).toBeInTheDocument();
    expect(within(lowRow).getByText('25.0000 CAPSULE')).toBeInTheDocument();
    expect(within(lowRow).getByText('15.0000 CAPSULE')).toBeInTheDocument();
    expect(within(lowRow).getByText('+ 6.0000 CAPSULE expired')).toBeInTheDocument();
    expect(within(lowRow).queryByText('Out of stock')).not.toBeInTheDocument();
  });

  it('filters to items with nothing usable left', () => {
    renderLowStock();

    fireEvent.click(screen.getByRole('button', { name: /Filter running low/ }));
    const outFilter = screen.getAllByRole('menuitem', { hidden: true })
      .find((menuItem) => menuItem.textContent?.includes('Out of stock (1)'));
    if (!outFilter) throw new Error('Out-of-stock filter missing');
    fireEvent.click(outFilter);

    expect(screen.getByRole('row', { name: /OUT-1/ })).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /LOW-1/ })).not.toBeInTheDocument();
  });

  it('searches by item code or name', () => {
    renderLowStock();

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search running low' }), {
      target: { value: 'healthy' },
    });

    // "Healthy item" has a level but is above its minimum, so it is not in the
    // worklist at all.
    expect(screen.queryByRole('row', { name: /OUT-1/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /LOW-1/ })).not.toBeInTheDocument();
    expect(screen.getByText('No item matches this filter.')).toBeInTheDocument();
  });

  it('sets a level for an item that is not low yet, in base units', () => {
    renderLowStock();

    fireEvent.click(screen.getByRole('button', { name: 'Set a minimum level' }));

    const dialog = screen.getByRole('dialog', { name: 'Set a minimum level' });
    expect(within(dialog).getByText('A reorder reminder, not a stock rule')).toBeInTheDocument();

    const itemCombo = within(dialog).getByRole('combobox', { name: 'Item to track' });
    fireEvent.change(itemCombo, { target: { value: 'OK-1' } });
    fireEvent.click(within(dialog).getByRole('option', { name: /OK-1/ }));

    expect(within(dialog).getByText(/currently tracks it at 5\.0000 TABLET/)).toBeInTheDocument();
    expect(within(dialog).getByText('5.0000 TABLET ≈ 0.05 BOX (1 BOX = 100.0000 TABLET).')).toBeInTheDocument();

    fireEvent.change(within(dialog).getByLabelText('Minimum level (TABLET)'), {
      target: { value: '150' },
    });

    expect(within(dialog).getByText('150 TABLET ≈ 1.5 BOX (1 BOX = 100.0000 TABLET).')).toBeInTheDocument();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Save minimum' }));

    expect(formState.posted).toHaveLength(1);
    const posted = formState.posted[0];
    if (posted === undefined) throw new Error('Minimum level was not submitted');
    expect(posted.url).toBe('/workspaces/inventory/item-levels');
    expect(posted.data).toMatchObject({ item_id: 'item-ok', min_level: '150' });
  });

  it('changes an existing level and can stop tracking the item', () => {
    renderLowStock();

    const lowRow = screen.getByRole('row', { name: /LOW-1/ });
    fireEvent.click(within(lowRow).getByRole('button', { name: 'Change minimum' }));

    const dialog = screen.getByRole('dialog', { name: 'Set a minimum level' });
    expect((within(dialog).getByLabelText('Minimum level (CAPSULE)') as HTMLInputElement).value).toBe('25.0000');
    expect(within(dialog).getByText(/currently tracks it at 25\.0000 CAPSULE/)).toBeInTheDocument();

    fireEvent.change(within(dialog).getByLabelText('Minimum level (CAPSULE)'), {
      target: { value: '40' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save minimum' }));

    expect(formState.posted).toHaveLength(1);
    expect(formState.posted[0]?.data).toMatchObject({ item_id: 'item-low', min_level: '40' });

    fireEvent.click(within(lowRow).getByRole('button', { name: 'Change minimum' }));
    fireEvent.click(screen.getByRole('button', { name: 'Stop tracking this item' }));

    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/inventory/item-levels',
      { item_id: 'item-low', min_level: '0' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('explains the difference between "not tracked" and "above minimum"', () => {
    const { unmount } = render(
      <InventoryWorkspace
        {...baseProps}
        currentSection="low-stock"
        lowStock={[]}
        itemLevels={[]}
      />,
    );

    expect(
      screen.getByText('No minimum levels have been set at this facility yet. Set one to start tracking what to reorder.'),
    ).toBeInTheDocument();
    unmount();

    renderLowStock({ lowStock: [], itemLevels: levels });
    expect(screen.getByText('Everything tracked at this facility is above its minimum.')).toBeInTheDocument();
  });

  it('is read-only without the item-master permission', () => {
    renderLowStock({ canManageLevels: false });

    expect(screen.queryByRole('button', { name: 'Set a minimum level' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Change minimum' })).not.toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Running low (2 of 2)' })).toBeInTheDocument();
  });

  it('explains that minimum levels are per facility in General context', () => {
    renderLowStock({ contextMode: 'general', lowStock: [], itemLevels: [] });

    const region = screen.getByRole('region', { name: 'Running low' });
    expect(within(region).getByText('Select a facility')).toBeInTheDocument();
    expect(screen.queryByRole('table', { name: /Running low/ })).not.toBeInTheDocument();
  });
});
