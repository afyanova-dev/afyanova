import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { InventoryWorkspace, type InventoryWorkspaceProps } from '@/features/inventory';

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

vi.mock('@/layouts/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const props: InventoryWorkspaceProps = {
  items: [
    {
      id: 'item-amox',
      code: 'AMOX-500',
      name: 'Amoxicillin 500 mg capsule',
      category: 'ANTIBIOTIC',
      description: null,
      itemType: 'MEDICATION',
      unitOfMeasure: 'CAPSULE',
      packUnit: 'BOX',
      packSize: '100.0000',
      tracksBatch: false,
      tracksExpiry: false,
      tracksSerial: false,
      isControlled: false,
      active: true,
    },
    {
      id: 'item-glove',
      code: 'GLOVE-M',
      name: 'Examination Gloves Medium',
      category: 'PPE',
      description: null,
      itemType: 'PPE',
      unitOfMeasure: 'PAIR',
      packUnit: null,
      packSize: null,
      tracksBatch: false,
      tracksExpiry: false,
      tracksSerial: false,
      isControlled: false,
      active: true,
    },
  ],
  locations: [
    { id: 'location-1', code: 'PHARMACY', name: 'Pharmacy Store', locationType: 'STORE', active: true },
  ],
  selectedItemId: null,
  selectedLocationId: null,
  balances: [],
  canReceive: false,
  canMatchInvoices: false,
  canRequestAdjustment: false,
  canApproveAdjustments: false,
  currentUserId: 'user-9',
  canManageLevels: false,
  canManageItems: false,
  canManageLocations: false,
  canManageLocationTypes: false,
  contextMode: 'facility',
  itemTypes: ['MEDICATION', 'SUPPLY', 'PPE'],
  itemCategories: ['ANTIBIOTIC', 'OTHER'],
  unitsOfMeasure: ['CAPSULE', 'TABLET', 'BOX', 'PAIR'],
  locationTypes: [{ code: 'STORE', name: 'Store', scope: 'BUILT_IN' }],
  errors: {},
  status: null,
};

describe('Inventory buying units (packs)', () => {
  it('shows the buying unit beside the base unit in the catalogue', () => {
    render(<InventoryWorkspace {...props} currentSection="catalogue" />);

    expect(screen.getByText('1 BOX = 100 CAPSULE')).toBeInTheDocument();
    // An item with no pack shows the base unit alone.
    expect(screen.queryByText(/1 .* = /)).toBeInTheDocument();
  });

  it('receives a delivery in the buying unit and posts the base-unit quantity', () => {
    formState.posted.length = 0;
    render(<InventoryWorkspace {...props} canReceive />);

    fireEvent.click(screen.getByRole('button', { name: '+ Receive delivery' }));
    const drawer = screen.getByRole('dialog', { name: 'Receive delivery' });
    const itemCombobox = within(drawer).getByRole('combobox', { name: 'Add an item' });
    fireEvent.change(itemCombobox, {
      target: { value: 'AMOX-500' },
    });
    fireEvent.click(within(drawer).getByRole('option', { name: /AMOX-500/ }));
    fireEvent.click(within(drawer).getByRole('button', { name: 'Add line' }));

    const quantity = within(drawer).getByLabelText('AMOX-500 delivered in BOX');
    fireEvent.change(quantity, { target: { value: '3' } });

    expect(within(drawer).getByText('BOX × 100.0000 = 300 CAPSULE')).toBeInTheDocument();
    fireEvent.click(within(drawer).getByRole('button', { name: 'Post goods receipt' }));

    expect(formState.posted).toHaveLength(1);
    expect(formState.posted[0]?.url).toBe('/workspaces/inventory/goods-receipts');
    expect(formState.posted[0]?.data.lines).toEqual(expect.arrayContaining([
      expect.objectContaining({
        entered_unit: 'BOX',
        entered_quantity: '3',
        pack_size: '100.0000',
        quantity_delivered: '300.0000',
      }),
    ]));
  });

  it('offers the buying unit when adding an item and posts it', () => {
    formState.posted.length = 0;
    render(<InventoryWorkspace {...props} canManageItems />);

    fireEvent.click(screen.getByRole('button', { name: '+ Add item' }));
    const drawer = screen.getByText('Add catalogue item').closest('aside') as HTMLElement;

    const buyingUnit = within(drawer).getByLabelText('Buying unit') as HTMLSelectElement;
    expect(Array.from(buyingUnit.options).map((option) => option.value)).toEqual(['', ...props.unitsOfMeasure]);

    fireEvent.change(within(drawer).getByLabelText('Generic name (INN)'), { target: { value: 'Amoxicillin' } });
    fireEvent.change(within(drawer).getByLabelText('Item code'), { target: { value: 'AMOX-250' } });
    fireEvent.change(buyingUnit, { target: { value: 'BOX' } });
    fireEvent.change(within(drawer).getByLabelText('Base units in one BOX'), { target: { value: '100' } });

    expect(within(drawer).getByText('1 BOX = 100 TABLET')).toBeInTheDocument();

    fireEvent.click(within(drawer).getByRole('button', { name: 'Add item' }));

    expect(formState.posted).toHaveLength(1);
    expect(formState.posted[0]?.data).toMatchObject({ pack_unit: 'BOX', pack_size: '100' });
  });
});
