import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { describe, expect, it, vi } from 'vitest';
import { InventoryWorkspace } from '@/features/inventory';

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

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');

  return {
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
    useForm: (initial: Record<string, unknown>) => {
      const [data, setData] = React.useState(initial);

      return {
        data,
        errors: {},
        processing: false,
        setData: React.useCallback((key: string | Record<string, unknown>, value?: unknown) => {
          setData((previous: Record<string, unknown>) => (
            typeof key === 'string'
              ? { ...previous, [key]: value }
              : { ...previous, ...key }
          ));
        }, []),
        post: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
      };
    },
  };
});

vi.mock('@/layouts/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const props = {
  items: [
    {
      id: 'item-1',
      code: 'PARA-500',
      name: 'Paracetamol 500 mg',
      category: 'Analgesics',
      description: null,
      itemType: 'MEDICATION',
      unitOfMeasure: 'TABLET',
      packUnit: null,
      packSize: null,
      tracksBatch: true,
      tracksExpiry: true,
      tracksSerial: false,
      isControlled: false,
      active: true,
    },
    {
      id: 'item-2',
      code: 'GLOVE-M',
      name: 'Examination Gloves Medium',
      category: 'PPE',
      description: 'Single-use gloves',
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
    { id: 'location-2', code: 'MAIN-WH', name: 'Main Warehouse', locationType: 'WAREHOUSE', active: true },
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
  contextMode: 'facility' as const,
  itemTypes: ['MEDICATION', 'SUPPLY', 'PPE', 'EQUIPMENT', 'DEVICE', 'CONSUMABLE'],
  itemCategories: ['ANALGESIC', 'ANTIBIOTIC', 'OTHER'],
  unitsOfMeasure: ['TABLET', 'CAPSULE', 'VIAL', 'BOX', 'PIECE', 'PAIR', 'EACH'],
  locationTypes: [
    { code: 'WAREHOUSE', name: 'Warehouse', scope: 'BUILT_IN' as const },
    { code: 'STORE', name: 'Store', scope: 'BUILT_IN' as const },
    { code: 'QUARANTINE', name: 'Quarantine', scope: 'BUILT_IN' as const },
  ],
  errors: {},
  status: null,
};

describe('Inventory catalogue filters', () => {
  it('places location search and type filters in the Carbon table toolbar', () => {
    render(<InventoryWorkspace {...props} currentSection="locations" canManageLocations locationTypes={[
      ...props.locationTypes,
      { code: 'PHARMACY', name: 'Pharmacy', scope: 'FACILITY' },
    ]} />);

    expect(screen.getByRole('searchbox', { name: 'Search facility locations' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Filter by location type/ })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Filter by location type/ }));
    expect(screen.getAllByRole('menuitem', { hidden: true }).some((item) => item.textContent?.includes('Store (1)'))).toBe(true);
  });

  it('searches items by code/name/category and filters by item type', () => {
    render(<InventoryWorkspace {...props} currentSection="catalogue" />);

    expect(screen.getByRole('row', { name: /PARA-500/ })).toBeInTheDocument();
    expect(screen.getByRole('row', { name: /GLOVE-M/ })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Filter by item type/ }));
    const medicationFilter = screen.getAllByRole('menuitem', { hidden: true })
      .find((item) => item.textContent?.includes('MEDICATION (1)'));
    if (!medicationFilter) throw new Error('Medication type filter missing');
    fireEvent.click(medicationFilter);
    expect(screen.getByRole('row', { name: /PARA-500/ })).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /GLOVE-M/ })).not.toBeInTheDocument();

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search item catalogue' }), {
      target: { value: 'analgesics' },
    });
    expect(screen.getByRole('row', { name: /PARA-500/ })).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /GLOVE-M/ })).not.toBeInTheDocument();
  });

  it('searches locations and filters by location type', () => {
    render(<InventoryWorkspace {...props} currentSection="locations" />);

    expect(screen.getByRole('row', { name: /PHARMACY/ })).toBeInTheDocument();
    expect(screen.getByRole('row', { name: /MAIN-WH/ })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Filter by location type/ }));
    const storeFilter = screen.getAllByRole('menuitem', { hidden: true })
      .find((item) => item.textContent?.includes('Store (1)'));
    if (!storeFilter) throw new Error('Store location filter missing');
    fireEvent.click(storeFilter);
    expect(screen.getByRole('row', { name: /PHARMACY/ })).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /MAIN-WH/ })).not.toBeInTheDocument();

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search facility locations' }), {
      target: { value: 'pharmacy' },
    });
    expect(screen.getByRole('row', { name: /PHARMACY/ })).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /MAIN-WH/ })).not.toBeInTheDocument();
  });

  it('uses the Carbon search toolbar for the location types table', () => {
    render(<InventoryWorkspace {...props} currentSection="locations" />);
    fireEvent.click(screen.getByRole('tab', { name: 'Location Types' }));

    expect(screen.getByRole('searchbox', { name: 'Search location types' })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Location types' })).toBeInTheDocument();
  });

  it('offers lifecycle actions only to item and location managers', () => {
    const { rerender } = render(
      <InventoryWorkspace
        {...props}
        currentSection="catalogue"
        canManageItems
        items={[...props.items, { ...props.items[0]!, id: 'inactive-item', code: 'RETIRED', active: false }]}
      />,
    );

    expect(screen.getAllByRole('button', { name: 'Deactivate' })).toHaveLength(2);
    expect(screen.getByRole('button', { name: 'Reactivate' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Reactivate' }));
    expect(router.patch).toHaveBeenCalledWith(
      '/workspaces/inventory/items/inactive-item/status',
      { active: true },
      { preserveScroll: true },
    );

    vi.mocked(router.patch).mockClear();
    rerender(<InventoryWorkspace {...props} currentSection="locations" canManageLocations />);
    expect(screen.getAllByRole('button', { name: 'Deactivate' }).length).toBeGreaterThan(0);
    fireEvent.click(screen.getAllByRole('button', { name: 'Deactivate' })[0]!);
    expect(router.patch).toHaveBeenCalledWith(
      '/workspaces/inventory/locations/location-1/status',
      { active: false },
      { preserveScroll: true },
    );

    rerender(<InventoryWorkspace {...props} currentSection="catalogue" />);
    expect(screen.queryByRole('button', { name: 'Deactivate' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reactivate' })).not.toBeInTheDocument();
  });

  it('offers only the goods-receipt workbench as the receiving flow', () => {
    const { rerender } = render(<InventoryWorkspace {...props} canReceive currentSection="stock" />);

    expect(screen.getByRole('region', { name: 'Stock lookup' })).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: /Expiring Soon|Out of Stock/ })).not.toBeInTheDocument();

    rerender(<InventoryWorkspace {...props} canReceive currentSection="overview" />);

    expect(screen.getByRole('button', { name: '+ Receive delivery' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Receive stock|Receive more stock|Receive here/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Receive' })).not.toBeInTheDocument();
    expect(screen.queryByText(/Receipt history and ledger — coming soon/)).not.toBeInTheDocument();
  });

  it('shows facility-specific stock as unavailable in General context', () => {
    render(<InventoryWorkspace {...props} currentSection="stock" contextMode="general" canManageItems />);

    expect(screen.getByText('Stock lookup is facility-specific. Choose a facility in the header to inspect its stock.')).toBeInTheDocument();
  });

  it('shows location-first stock and lets users inspect an item at that location', () => {
    render(
      <InventoryWorkspace
        {...props}
        currentSection="stock"
        selectedLocationId="location-1"
        locationItems={[
          {
            itemId: 'item-1',
            itemCode: 'PARA-500',
            itemName: 'Paracetamol 500 mg',
            onHand: '100.0000',
            reserved: '20.0000',
            available: '80.0000',
            uom: 'TABLET',
            batches: [
              {
                batchId: 'batch-1',
                batchNumber: 'LOT-2026-01',
                expiryDate: '2027-12-31',
                onHand: '100.0000',
                reserved: '20.0000',
                available: '80.0000',
                uom: 'TABLET',
              },
            ],
          },
        ]}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Stock at Pharmacy Store' })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Location stock (1)' })).toBeInTheDocument();
    expect(screen.getByRole('searchbox', { name: 'Search items at location' })).toBeInTheDocument();
    expect(screen.getByRole('row', { name: /PARA-500/ })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Expand PARA-500 — Paracetamol 500 mg' }));
    expect(screen.getByRole('heading', { name: 'Batches for Paracetamol 500 mg (PARA-500)' })).toBeInTheDocument();
    expect(screen.getByText('LOT-2026-01')).toBeInTheDocument();
    expect(screen.getByText('2027-12-31')).toBeInTheDocument();
    expect(screen.getAllByText('100.0000 TABLET')).toHaveLength(2);
    fireEvent.click(screen.getByRole('button', { name: 'Collapse PARA-500 — Paracetamol 500 mg' }));
    expect(screen.queryByText('LOT-2026-01')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'View all items at location' }));
    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({
        item_id: undefined,
        location_id: 'location-1',
        section: 'stock',
      }),
      expect.any(Object),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Inspect item' }));
    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({
        item_id: 'item-1',
        location_id: 'location-1',
        section: 'stock',
      }),
      expect.any(Object),
    );
  });

  it('loads stock when a location is selected', () => {
    vi.mocked(router.get).mockClear();
    render(<InventoryWorkspace {...props} currentSection="stock" />);

    fireEvent.change(screen.getByRole('combobox', { name: 'Location' }), {
      target: { value: 'location-1' },
    });

    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({
        item_id: undefined,
        location_id: 'location-1',
        section: 'stock',
      }),
      expect.any(Object),
    );
  });

  it('shows the user department instead of a location picker for a single department location', () => {
    render(
      <InventoryWorkspace
        {...props}
        locations={[{
          id: 'location-1',
          code: 'PHARMACY',
          name: 'Pharmacy / Dispensing Area',
          locationType: 'STORE',
          active: true,
        }]}
        currentSection="stock"
        selectedLocationId="location-1"
        departmentScoped
        departmentName="Pharmaceutical"
      />,
    );

    expect(screen.getByText('Department')).toBeInTheDocument();
    expect(screen.getByText('Pharmaceutical')).toBeInTheDocument();
    expect(screen.getByText('Stock location: Pharmacy / Dispensing Area')).toBeInTheDocument();
    expect(screen.queryByRole('combobox', { name: 'Location' })).not.toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Stock at Pharmacy / Dispensing Area' })).toBeInTheDocument();
  });

  it('requests server-side search, sorting, and pagination for location stock', () => {
    vi.mocked(router.get).mockClear();
    render(
      <InventoryWorkspace
        {...props}
        currentSection="stock"
        selectedLocationId="location-1"
        locationItems={[
          {
            itemId: 'item-1',
            itemCode: 'PARA-500',
            itemName: 'Paracetamol 500 mg',
            onHand: '100.0000',
            reserved: '20.0000',
            available: '80.0000',
            uom: 'TABLET',
            batches: [],
          },
        ]}
        locationItemPagination={{
          currentPage: 1,
          lastPage: 3,
          perPage: 15,
          total: 31,
          sortBy: 'code',
          sortDirection: 'asc',
          search: '',
        }}
      />,
    );

    const search = screen.getByRole('searchbox', { name: 'Search items at location' });
    fireEvent.change(search, { target: { value: 'para' } });
    fireEvent.keyDown(search, { key: 'Enter', code: 'Enter' });
    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({ location_id: 'location-1', q: 'para' }),
      expect.any(Object),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Name' }));
    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({ location_id: 'location-1', sort_by: 'name', sort_direction: 'asc', page: undefined }),
      expect.any(Object),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Name' }));
    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({ sort_by: 'name', sort_direction: 'desc' }),
      expect.any(Object),
    );

    expect(screen.queryByRole('combobox', { name: 'Sort by' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({ location_id: 'location-1', page: 2, per_page: 15 }),
      expect.any(Object),
    );
  });

  it('opens and closes the Add Item right workspace drawer', () => {
    render(<InventoryWorkspace {...props} canManageItems />);

    expect(screen.queryByText('Add catalogue item')).not.toBeInTheDocument();

    // Click "+ Add item" button in overview
    const addItemBtn = screen.getByRole('button', { name: '+ Add item' });
    fireEvent.click(addItemBtn);

    // Now drawer is displayed
    const drawerHeading = screen.getByText('Add catalogue item');
    expect(drawerHeading.closest('aside')).toHaveStyle({ display: 'flex' });

    // Click Cancel to close
    const cancelBtn = screen.getByRole('button', { name: 'Cancel' });
    fireEvent.click(cancelBtn);
    expect(screen.queryByText('Add catalogue item')).not.toBeInTheDocument();
  });

  it('feeds the Add Item pickers from the server vocabularies and warns on a duplicate generic name', () => {
    render(<InventoryWorkspace {...props} canManageItems />);

    fireEvent.click(screen.getByRole('button', { name: '+ Add item' }));
    const drawer = screen.getByText('Add catalogue item').closest('aside') as HTMLElement;

    // The Name field is the generic (INN) name.
    const nameField = within(drawer).getByLabelText('Generic name (INN)');

    // Type / base-unit pickers come from the server vocabularies with sane defaults.
    const typeSelect = within(drawer).getByLabelText('Item type') as HTMLSelectElement;
    expect(Array.from(typeSelect.options).map((option) => option.value)).toEqual(props.itemTypes);
    expect(typeSelect.value).toBe('MEDICATION');

    const uomSelect = within(drawer).getByLabelText('Base unit of measure') as HTMLSelectElement;
    expect(Array.from(uomSelect.options).map((option) => option.value)).toEqual(props.unitsOfMeasure);
    expect(uomSelect.value).toBe('TABLET');

    // Category is optional and offers the controlled list behind a blank option.
    const categorySelect = within(drawer).getByLabelText('Category (optional)') as HTMLSelectElement;
    expect(Array.from(categorySelect.options).map((option) => option.value)).toEqual(['', ...props.itemCategories]);

    // No warning until the typed name collides with an existing active item.
    expect(within(drawer).queryByText('Possible duplicate')).not.toBeInTheDocument();
    fireEvent.change(nameField, { target: { value: 'Paracetamol 500 mg' } });
    expect(within(drawer).getByText('Possible duplicate')).toBeInTheDocument();
  });

  it('renders stock balance details when item and location are selected', () => {
    render(
      <InventoryWorkspace
        {...props}
        selectedItemId="item-1"
        selectedLocationId="location-1"
        balances={[
          {
            onHand: '100',
            reserved: '20',
            available: '80',
            batchId: 'batch-1',
            batchNumber: 'BATCH-001',
            expiryDate: '2027-12-31',
            unitOfMeasure: 'TABLET',
          },
        ]}
      />,
    );

    // Switch to Stock lookup section via overview action
    fireEvent.click(screen.getByRole('button', { name: 'Check stock by location' }));

    expect(screen.getAllByText('Paracetamol 500 mg').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Pharmacy Store').length).toBeGreaterThan(0);
    expect(screen.getByLabelText('Stock balance')).toBeInTheDocument();
    expect(screen.getByText(/BATCH-001/)).toBeInTheDocument();
    expect(screen.getByRole('searchbox', { name: 'Search stock batches' })).toBeInTheDocument();
  });

  it('syncs stock lookup selectors when an item or location is opened from a list', () => {
    const { rerender } = render(<InventoryWorkspace {...props} currentSection="stock" />);

    rerender(
      <InventoryWorkspace
        {...props}
        currentSection="stock"
        selectedItemId="item-1"
        selectedLocationId="location-1"
      />,
    );

    expect(screen.getByRole('combobox', { name: 'Item' })).toHaveValue('PARA-500 — Paracetamol 500 mg (MEDICATION)');
    expect(screen.getByLabelText('Location')).toHaveValue('location-1');
    expect(screen.getByRole('button', { name: 'View stock' })).toBeEnabled();
  });

  it('keeps the movement ledger drawer hidden until a ledger is loaded, then requests it for the selection', () => {
    vi.mocked(router.get).mockClear();
    render(
      <InventoryWorkspace
        {...props}
        currentSection="stock"
        selectedItemId="item-1"
        selectedLocationId="location-1"
      />,
    );

    expect(screen.queryByText('Stock movement ledger')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'View movement ledger' }));
    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({
        section: 'stock',
        item_id: 'item-1',
        location_id: 'location-1',
        view: 'ledger',
      }),
      expect.any(Object),
    );
  });

  it('renders the movement ledger with running balance, batch, source, and drift', () => {
    render(
      <InventoryWorkspace
        {...props}
        currentSection="stock"
        selectedItemId="item-1"
        selectedLocationId="location-1"
        balances={[
          {
            onHand: '100.0000',
            reserved: '0.0000',
            available: '100.0000',
            batchId: 'batch-1',
            batchNumber: 'LOT-1',
            expiryDate: null,
            unitOfMeasure: 'TABLET',
          },
        ]}
        ledger={{
          itemId: 'item-1',
          locationId: 'location-1',
          currentOnHandQty: '100.0000',
          ledgerSum: '100.0000',
          drift: '0.0000',
          entries: [
            {
              movementId: 'mv-1',
              movementNumber: 'MV-0001',
              movementType: 'RECEIPT',
              direction: 'IN',
              quantity: '100.0000',
              uom: 'TABLET',
              enteredUnit: null,
              enteredQuantity: null,
              packSize: null,
              runningBalance: '100.0000',
              sourceType: 'OPENING_BALANCE',
              sourceRef: null,
              poRef: null,
              batchId: 'batch-1',
              reasonText: null,
              actorUserId: 'user-1',
              occurredAt: '2026-01-01T08:00:00+00:00',
            },
          ],
        }}
      />,
    );

    const drawer = screen.getByRole('dialog', { name: 'Stock movement ledger' });
    expect(drawer).toHaveStyle({ display: 'flex' });
    expect(within(drawer).getByRole('table', { name: 'Movements (1)' })).toBeInTheDocument();
    expect(within(drawer).getByText('MV-0001')).toBeInTheDocument();
    expect(within(drawer).getByText('LOT-1')).toBeInTheDocument();
    expect(within(drawer).getByText('Opening balance')).toBeInTheDocument();
    expect(within(drawer).getByText('Balanced')).toBeInTheDocument();
  });

  it('flags reconciliation drift in the movement ledger', () => {
    render(
      <InventoryWorkspace
        {...props}
        currentSection="stock"
        selectedItemId="item-1"
        selectedLocationId="location-1"
        ledger={{
          itemId: 'item-1',
          locationId: 'location-1',
          currentOnHandQty: '95.0000',
          ledgerSum: '100.0000',
          drift: '-5.0000',
          entries: [],
        }}
      />,
    );

    expect(screen.getByText('Reconciliation required')).toBeInTheDocument();
    expect(screen.getByText(/No stock movements have been recorded/)).toBeInTheDocument();
  });

  it('renders the facility adjustment worklist with status and decision', () => {
    render(
      <InventoryWorkspace
        {...props}
        currentSection="adjustments"
        adjustments={[
          {
            adjustmentId: 'adj-1',
            itemId: 'item-1',
            locationId: 'location-1',
            batchId: null,
            direction: 'DECREASE',
            quantity: '5.0000',
            reasonCode: 'DAMAGED',
            reasonText: 'Broken vials',
            witnessName: null,
            status: 'PENDING_APPROVAL',
            requestedByUserId: 'user-1',
            requestedAt: '2026-01-01T09:00:00+00:00',
            approvedByUserId: null,
            approvedAt: null,
            rejectionReason: null,
            resultingMovementId: null,
          },
          {
            adjustmentId: 'adj-2',
            itemId: 'item-2',
            locationId: 'location-2',
            batchId: null,
            direction: 'INCREASE',
            quantity: '3.0000',
            reasonCode: 'DATA_ERROR',
            reasonText: null,
            witnessName: null,
            status: 'REJECTED',
            requestedByUserId: 'user-2',
            requestedAt: '2026-01-02T09:00:00+00:00',
            approvedByUserId: 'user-3',
            approvedAt: '2026-01-02T10:00:00+00:00',
            rejectionReason: 'Not justified',
            resultingMovementId: null,
          },
        ]}
      />,
    );

    expect(screen.getByRole('region', { name: 'Adjustments' })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Adjustments (2)' })).toBeInTheDocument();

    const pendingRow = screen.getByRole('row', { name: /PARA-500/ });
    expect(within(pendingRow).getByText('PARA-500 — Paracetamol 500 mg')).toBeInTheDocument();
    expect(within(pendingRow).getByText('PHARMACY — Pharmacy Store')).toBeInTheDocument();
    expect(within(pendingRow).getByText('−5.0000')).toBeInTheDocument();
    expect(within(pendingRow).getByText('Damaged · Broken vials')).toBeInTheDocument();
    expect(within(pendingRow).getByText('Pending approval')).toBeInTheDocument();
    expect(within(pendingRow).getByText('Awaiting approval')).toBeInTheDocument();

    const rejectedRow = screen.getByRole('row', { name: /GLOVE-M/ });
    expect(within(rejectedRow).getByText('GLOVE-M — Examination Gloves Medium')).toBeInTheDocument();
    expect(within(rejectedRow).getByText('Rejected')).toBeInTheDocument();
    expect(within(rejectedRow).getByText('Not justified')).toBeInTheDocument();
  });

  it('filters the adjustment worklist by status through the server', () => {
    vi.mocked(router.get).mockClear();
    render(<InventoryWorkspace {...props} currentSection="adjustments" adjustments={[]} />);

    expect(screen.getByText('No stock adjustments match the current filter')).toBeInTheDocument();

    const openStatusFilter = (): void => {
      fireEvent.click(screen.getByRole('button', { name: /Filter by status/ }));
    };
    const clickMenuItem = (label: string): void => {
      const item = screen.getAllByRole('menuitem', { hidden: true })
        .find((menuItem) => menuItem.textContent?.trim() === label);
      if (!item) throw new Error(`${label} status filter missing`);
      fireEvent.click(item);
    };

    openStatusFilter();
    clickMenuItem('Approved');
    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({ section: 'adjustments', adjustment_status: 'APPROVED' }),
      expect.any(Object),
    );

    openStatusFilter();
    clickMenuItem('All statuses');
    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({ section: 'adjustments', adjustment_status: undefined }),
      expect.any(Object),
    );
  });

  it('opens the adjustment request drawer from the worklist when permitted', () => {
    render(
      <InventoryWorkspace
        {...props}
        currentSection="adjustments"
        adjustments={[]}
        canRequestAdjustment
      />,
    );

    expect(screen.queryByText('Request stock adjustment')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Request adjustment' }));

    const drawerHeading = screen.getByText('Request stock adjustment');
    expect(drawerHeading.closest('aside')).toHaveStyle({ display: 'flex' });

    const dialog = screen.getByRole('dialog', { name: 'Request stock adjustment' });
    expect(within(dialog).getByLabelText('Direction')).toBeInTheDocument();
    expect(within(dialog).getByLabelText('Reason')).toBeInTheDocument();
    expect(within(dialog).getByLabelText('Batch number')).toBeInTheDocument();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByText('Request stock adjustment')).not.toBeInTheDocument();
  });

  it('hides the adjustment request action without the create permission', () => {
    render(<InventoryWorkspace {...props} currentSection="adjustments" adjustments={[]} />);

    expect(screen.queryByRole('button', { name: 'Request adjustment' })).not.toBeInTheDocument();
    expect(screen.queryByText('Request stock adjustment')).not.toBeInTheDocument();
  });

  const pendingForAnotherUser = {
    adjustmentId: 'adj-other',
    itemId: 'item-1',
    locationId: 'location-1',
    batchId: null,
    direction: 'DECREASE',
    quantity: '5.0000',
    reasonCode: 'DAMAGED',
    reasonText: null,
    witnessName: null,
    status: 'PENDING_APPROVAL',
    requestedByUserId: 'user-1',
    requestedAt: '2026-01-01T09:00:00+00:00',
    approvedByUserId: null,
    approvedAt: null,
    rejectionReason: null,
    resultingMovementId: null,
  };

  it('offers approve and reject to eligible approvers and hides self-approval', () => {
    vi.mocked(router.post).mockClear();
    render(
      <InventoryWorkspace
        {...props}
        currentSection="adjustments"
        canApproveAdjustments
        currentUserId="user-9"
        adjustments={[
          pendingForAnotherUser,
          {
            ...pendingForAnotherUser,
            adjustmentId: 'adj-own',
            itemId: 'item-2',
            locationId: 'location-2',
            requestedByUserId: 'user-9',
          },
        ]}
      />,
    );

    const otherRow = screen.getByRole('row', { name: /PARA-500/ });
    expect(within(otherRow).getByRole('button', { name: 'Approve' })).toBeInTheDocument();
    expect(within(otherRow).getByRole('button', { name: 'Reject' })).toBeInTheDocument();

    const ownRow = screen.getByRole('row', { name: /GLOVE-M/ });
    expect(within(ownRow).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(within(ownRow).getByText('Awaiting another approver')).toBeInTheDocument();

    fireEvent.click(within(otherRow).getByRole('button', { name: 'Approve' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/inventory/adjustments/adj-other/approve',
      expect.objectContaining({ idempotency_key: expect.any(String) }),
      expect.any(Object),
    );
  });

  it('requires a decision note before rejecting an adjustment', () => {
    vi.mocked(router.post).mockClear();
    render(
      <InventoryWorkspace
        {...props}
        currentSection="adjustments"
        canApproveAdjustments
        currentUserId="user-9"
        adjustments={[pendingForAnotherUser]}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Reject' }));

    expect(screen.getByText('Reject stock adjustment')).toBeInTheDocument();
    const confirm = screen.getByRole('button', { name: 'Reject adjustment' });
    expect(confirm).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Decision note'), { target: { value: 'Not justified' } });
    expect(confirm).toBeEnabled();

    fireEvent.click(confirm);
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/inventory/adjustments/adj-other/reject',
      expect.objectContaining({ reason: 'Not justified', idempotency_key: expect.any(String) }),
      expect.any(Object),
    );
  });

  const activeReservation = {
    reservationId: 'res-1',
    itemId: 'item-1',
    locationId: 'location-1',
    batchId: null,
    reservedQty: '12.0000',
    consumerType: 'PHARMACY_ORDER',
    consumerRef: 'RX-1001',
    reason: null,
    holdUntil: '2026-01-05T09:00:00+00:00',
    status: 'ACTIVE',
    releasedReason: null,
    fulfilledByMovementId: null,
    requestedByUserId: 'user-1',
    createdAt: '2026-01-01T09:00:00+00:00',
  };

  it('renders the facility reservation worklist read-only and filters it by status', () => {
    vi.mocked(router.get).mockClear();
    render(
      <InventoryWorkspace {...props} currentSection="reservations" reservations={[activeReservation]} />,
    );

    const region = screen.getByRole('region', { name: 'Reservations' });
    const row = within(region).getByRole('row', { name: /PARA-500/ });
    expect(within(row).getByText('12.0000')).toBeInTheDocument();
    expect(within(row).getByText('Pharmacy order · RX-1001')).toBeInTheDocument();
    expect(within(row).getByText('Active')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Filter by status/ }));
    const releasedFilter = screen.getAllByRole('menuitem', { hidden: true })
      .find((item) => item.textContent?.trim() === 'Released');
    if (!releasedFilter) throw new Error('Released status filter missing');
    fireEvent.click(releasedFilter);

    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({ section: 'reservations', reservation_status: 'RELEASED' }),
      expect.any(Object),
    );
  });

  it('shows an empty reservation state', () => {
    render(<InventoryWorkspace {...props} currentSection="reservations" reservations={[]} />);

    expect(screen.getByText('No stock reservations match the current filter')).toBeInTheDocument();
  });

  it('renders the facility stock-on-hand listing and forwards paging and filters', () => {
    vi.mocked(router.get).mockClear();
    render(
      <InventoryWorkspace
        {...props}
        currentSection="stock-on-hand"
        facilityStock={[
          {
            balanceId: 'bal-1',
            itemId: 'item-1',
            itemCode: 'PARA-500',
            itemName: 'Paracetamol 500 mg',
            locationId: 'location-1',
            locationCode: 'PHARMACY',
            batchId: 'batch-1',
            batchNumber: 'B-001',
            expiryDate: '2027-12-31',
            onHand: '100.0000',
            reserved: '20.0000',
            available: '80.0000',
            uom: 'TABLET',
          },
        ]}
        facilityStockPagination={{
          currentPage: 1,
          lastPage: 2,
          perPage: 15,
          total: 16,
          sortBy: 'code',
          sortDirection: 'asc',
          search: '',
          locationId: null,
        }}
      />,
    );

    const region = screen.getByRole('region', { name: 'Stock on hand' });
    const row = within(region).getByRole('row', { name: /PARA-500/ });
    expect(within(row).getByText('PHARMACY — Pharmacy Store')).toBeInTheDocument();
    expect(within(row).getByText('B-001')).toBeInTheDocument();
    expect(within(row).getByText('100.0000 TABLET')).toBeInTheDocument();
    expect(within(row).getByText('80.0000 TABLET')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Filter by location/ }));
    const locationFilter = screen.getAllByRole('menuitem', { hidden: true })
      .find((item) => item.textContent?.trim() === 'MAIN-WH — Main Warehouse');
    if (!locationFilter) throw new Error('Location filter missing');
    fireEvent.click(locationFilter);

    expect(router.get).toHaveBeenLastCalledWith(
      '/workspaces/inventory',
      expect.objectContaining({ section: 'stock-on-hand', location_id: 'location-2' }),
      expect.any(Object),
    );
  });
});

describe('PO-aware receiving', () => {
  const openPurchaseOrder = {
    purchaseOrderId: 'po-1',
    poNumber: 'PO-2026-0001',
    status: 'ISSUED',
    lines: [
      {
        lineId: 'line-1',
        lineNo: 1,
        itemRef: 'item-1',
        itemCode: 'PARA-500',
        itemName: 'Paracetamol 500 mg',
        orderedQty: '10.0000',
        receivedQty: '4.0000',
        remainingQty: '6.0000',
        uom: 'TABLET',
        enteredUnit: null,
        enteredQty: null,
        packSize: null,
      },
      {
        lineId: 'line-2',
        lineNo: 2,
        itemRef: 'item-2',
        itemCode: 'GLOVE-M',
        itemName: 'Examination Gloves Medium',
        orderedQty: '20.0000',
        receivedQty: '0.0000',
        remainingQty: '20.0000',
        uom: 'PAIR',
        enteredUnit: null,
        enteredQty: null,
        packSize: null,
      },
      {
        lineId: 'line-3',
        lineNo: 3,
        itemRef: 'item-3',
        itemCode: 'DEVICE-01',
        itemName: 'Serial tracked device',
        orderedQty: '2.0000',
        receivedQty: '0.0000',
        remainingQty: '2.0000',
        uom: 'EACH',
        enteredUnit: null,
        enteredQty: null,
        packSize: null,
      },
    ],
  };

  it('opens the multi-line receiving workbench and adds all open purchase-order lines', async () => {
    const fetchMock = vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      const response = url.includes('/lines')
        ? {
          data: openPurchaseOrder.lines,
          meta: { currentPage: 1, lastPage: 1, perPage: 100, total: 3 },
        }
        : url.includes(encodeURIComponent('no matching order'))
          ? {
            data: [],
            meta: { currentPage: 1, lastPage: 1, perPage: 20, total: 0 },
          }
          : {
            data: [{
            purchaseOrderId: openPurchaseOrder.purchaseOrderId,
            poNumber: openPurchaseOrder.poNumber,
            status: openPurchaseOrder.status,
            lines: [],
            openLineCount: 3,
            remainingLineCount: 3,
          }],
          meta: { currentPage: 1, lastPage: 1, perPage: 20, total: 1 },
          };

      return Promise.resolve({
        ok: true,
        json: async () => response,
      });
    });

    vi.stubGlobal('fetch', fetchMock);

    try {
      render(
        <InventoryWorkspace
          {...props}
          items={props.items.map((item) => item.id === 'item-1'
            ? { ...item, packUnit: 'BOX', packSize: '100.0000' }
            : item)}
          canReceive
          canMatchInvoices
          locations={[
            ...props.locations,
            { id: 'location-quarantine', code: 'QUARANTINE', name: 'Quarantine', locationType: 'QUARANTINE', active: true },
          ]}
          canReceiveAgainstPurchaseOrders
        />,
      );
      fireEvent.click(screen.getByRole('button', { name: '+ Receive delivery' }));

      const drawer = screen.getByRole('dialog', { name: 'Receive delivery' });
      fireEvent.click(within(drawer).getByRole('combobox', { name: 'Purchase order' }));
      const poOption = await within(drawer).findByRole('option', { name: /PO-2026-0001/ });
      fireEvent.click(poOption);
      const addAll = await within(drawer).findByRole('button', { name: 'Add all remaining' });
      await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
      fireEvent.click(addAll);

      expect(within(drawer).getByText(/2 of 500 lines/)).toBeInTheDocument();
      expect(within(drawer).getByText(/26 accepted/)).toBeInTheDocument();
      expect(within(drawer).getByLabelText('PARA-500 delivered in TABLET')).toHaveValue(6);
      expect(within(drawer).getByLabelText('GLOVE-M delivered in PAIR')).toHaveValue(20);
      expect(within(drawer).getByRole('button', { name: 'Post goods receipt' })).toBeEnabled();

      fireEvent.change(within(drawer).getByRole('combobox', { name: 'Purchase order' }), {
        target: { value: 'no matching order' },
      });
      await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
      expect(within(drawer).getByRole('combobox', { name: 'Purchase order' }))
        .toHaveValue('PO-2026-0001 — 3 line(s) awaiting delivery');
    } finally {
      vi.unstubAllGlobals();
    }
  });

  it('applies pasted delivery quantities to the matching purchase-order lines', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: [{
            purchaseOrderId: openPurchaseOrder.purchaseOrderId,
            poNumber: openPurchaseOrder.poNumber,
            status: openPurchaseOrder.status,
            lines: [],
            openLineCount: 3,
            remainingLineCount: 3,
          }],
          meta: { currentPage: 1, lastPage: 1, perPage: 20, total: 1 },
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          data: openPurchaseOrder.lines,
          meta: { currentPage: 1, lastPage: 1, perPage: 100, total: 3 },
        }),
      });

    vi.stubGlobal('fetch', fetchMock);

    try {
      render(
        <InventoryWorkspace
          {...props}
          canReceive
          canReceiveAgainstPurchaseOrders
        />,
      );
      fireEvent.click(screen.getByRole('button', { name: '+ Receive delivery' }));

      const drawer = screen.getByRole('dialog', { name: 'Receive delivery' });
      fireEvent.click(within(drawer).getByRole('combobox', { name: 'Purchase order' }));
      const poOption = await within(drawer).findByRole('option', { name: /PO-2026-0001/ });
      fireEvent.click(poOption);

      await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
      const importButton = await within(drawer).findByRole('button', { name: 'Paste quantities' });
      fireEvent.click(importButton);

      const modal = screen.getByRole('dialog', { name: 'Paste received quantities' });
      fireEvent.change(within(modal).getByLabelText('Received quantities'), {
        target: { value: '1, 6\n2, 20' },
      });
      fireEvent.click(within(modal).getByRole('button', { name: 'Apply quantities' }));

      expect(within(drawer).getByText(/2 of 500 lines/)).toBeInTheDocument();
      expect(within(drawer).getByLabelText('PARA-500 delivered in TABLET')).toHaveValue(6);
      expect(within(drawer).getByLabelText('GLOVE-M delivered in PAIR')).toHaveValue(20);
    } finally {
      vi.unstubAllGlobals();
    }
  });

  it('renders the goods-receipt register and the opened GRN document', () => {
    render(
      <InventoryWorkspace
        {...props}
        canReceive
        canMatchInvoices
        currentSection="receipts"
        goodsReceipts={[{
          goodsReceiptId: 'receipt-1',
          receiptNumber: 'GRN-00000001',
          receivedAt: '2026-10-21T08:30:00+00:00',
          sourceType: 'PROCUREMENT',
          poRef: 'PO-000001',
          supplierRef: 'supplier-1',
          locationId: 'location-1',
          locationCode: 'MAIN-WH',
          locationName: 'Main Warehouse',
          deliveryNoteNumber: 'DN-1',
          invoiceNumber: 'INV-1',
          invoiceStatus: 'RECEIVED',
          invoiceTotal: '120000.00',
          lineCount: 1,
          rejectedLineCount: 0,
        }]}
        goodsReceiptPagination={{ currentPage: 1, lastPage: 1, perPage: 25, total: 1, search: '' }}
        goodsReceiptDetail={{
          goodsReceiptId: 'receipt-1',
          receiptNumber: 'GRN-00000001',
          receivedAt: '2026-10-21T08:30:00+00:00',
          recordedAt: '2026-10-21T08:31:00+00:00',
          sourceType: 'PROCUREMENT',
          poRef: 'PO-000001',
          supplierRef: 'supplier-1',
          locationId: 'location-1',
          locationCode: 'MAIN-WH',
          locationName: 'Main Warehouse',
          deliveryNoteNumber: 'DN-1',
          deliveryNoteMissingReason: null,
          invoiceNumber: 'INV-1',
          invoiceDate: '2026-10-21',
          invoiceStatus: 'RECEIVED',
          invoiceCurrency: 'TZS',
          invoiceTotal: '120000.00',
          status: 'POSTED',
          matchStatus: 'VARIANCE',
          remarks: null,
          receivedByUserId: 'user-9',
          lines: [{
            receiptLineId: 'line-1',
            itemId: 'item-1',
            itemCode: 'PARA-500',
            itemName: 'Paracetamol 500 mg',
            poLineRef: 'po-line-1',
            enteredUnit: 'BOX',
            enteredQuantity: '1',
            packSize: '100',
            quantityDelivered: '100.0000',
            quantityRejected: '0.0000',
            quantityAccepted: '100.0000',
            poUnit: 'BOX',
            poUnitPrice: '120000.00',
            invoiceQuantity: '0.9',
            invoiceUnitPrice: '125000.00',
            uom: 'TABLET',
            batchNumber: 'B-1',
            expiryDate: '2027-10-21',
            manufactureDate: null,
            condition: 'GOOD',
            substitutedForItemId: null,
            conditionNotes: null,
            rejectionReason: null,
            movementNumber: 'MV-000001',
          }],
        }}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Goods receipts' })).toBeInTheDocument();
    expect(screen.getByText('GRN-00000001')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Goods receipt GRN-00000001' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Print GRN' })).toHaveAttribute(
      'href',
      '/workspaces/inventory/goods-receipts/receipt-1/print',
    );
    expect(screen.getByLabelText('Invoice number')).toHaveValue('INV-1');
    expect(screen.getByRole('button', { name: 'Update invoice' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Invoice matching' })).toBeInTheDocument();
    expect(screen.getByLabelText('Invoice quantity (BOX)')).toHaveValue(0.9);
    expect(screen.getByLabelText('Invoice unit price (TZS / BOX)')).toHaveValue(125000);
    expect(screen.getByText('Quantity variance: -0.1000 BOX')).toBeInTheDocument();
    expect(screen.getByText('Price variance: TZS +5000.00 / BOX')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Export invoice match CSV' })).toHaveAttribute(
      'href',
      '/workspaces/inventory/goods-receipts/receipt-1/invoice-match/export',
    );
    expect(screen.getByRole('heading', { name: 'Return stock to supplier' })).toBeInTheDocument();
    expect(screen.getByLabelText('Return from location')).toHaveValue('location-1');
  });

  it('marks controlled medicines in the catalogue', () => {
    const controlledItems = [{ ...props.items[0]!, isControlled: true }, props.items[1]!];
    render(<InventoryWorkspace {...props} currentSection="catalogue" items={controlledItems} />);

    const row = screen.getByRole('row', { name: /PARA-500/ });
    expect(within(row).getByText('Controlled')).toBeInTheDocument();
    expect(within(screen.getByRole('row', { name: /GLOVE-M/ })).queryByText('Controlled')).not.toBeInTheDocument();
  });

  it('asks for a witness when removing a controlled medicine', () => {
    const controlledItems = [{ ...props.items[0]!, isControlled: true }, props.items[1]!];
    render(
      <InventoryWorkspace
        {...props}
        currentSection="adjustments"
        adjustments={[]}
        canRequestAdjustment
        items={controlledItems}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Request adjustment' }));
    const dialog = screen.getByRole('dialog', { name: 'Request stock adjustment' });

    const witness = within(dialog).getByLabelText('Witness (required for controlled medicine)');
    expect(witness).toBeInTheDocument();

    // A quantity alone is not enough for controlled stock.
    fireEvent.change(within(dialog).getByLabelText('Quantity (TABLET)'), { target: { value: '1' } });
    expect(within(dialog).getByRole('button', { name: 'Request adjustment' })).toBeDisabled();

    fireEvent.change(witness, { target: { value: 'A. Nurse' } });
    expect(within(dialog).getByRole('button', { name: 'Request adjustment' })).toBeEnabled();
  });

  it('does not ask for a witness when the selected item is not controlled', () => {
    render(<InventoryWorkspace {...props} currentSection="adjustments" adjustments={[]} canRequestAdjustment />);

    fireEvent.click(screen.getByRole('button', { name: 'Request adjustment' }));
    const dialog = screen.getByRole('dialog', { name: 'Request stock adjustment' });

    expect(within(dialog).queryByLabelText('Witness (required for controlled medicine)')).not.toBeInTheDocument();
    expect(within(dialog).queryByLabelText('Witness')).not.toBeInTheDocument();
  });
});
