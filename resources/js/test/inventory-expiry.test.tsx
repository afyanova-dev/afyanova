import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
  InventoryWorkspace,
  type ExpiringStock,
  type InventoryWorkspaceProps,
  type StockAdjustment,
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

const expiring: ExpiringStock[] = [
  {
    balanceId: 'bal-exp',
    itemId: 'item-1',
    itemCode: 'CEF-1G',
    itemName: 'Ceftriaxone 1 g',
    locationId: 'location-1',
    locationCode: 'PHARMACY',
    batchId: 'batch-exp',
    batchNumber: 'CEF-EXP',
    expiryDate: '2026-10-16',
    daysRemaining: -3,
    onHand: '10.0000',
    uom: 'VIAL',
    expired: true,
    controlled: false,
  },
  {
    balanceId: 'bal-soon',
    itemId: 'item-2',
    itemCode: 'AMOX-500',
    itemName: 'Amoxicillin 500 mg',
    locationId: 'location-1',
    locationCode: 'PHARMACY',
    batchId: 'batch-soon',
    batchNumber: 'AMOX-SOON',
    expiryDate: '2026-10-31',
    daysRemaining: 12,
    onHand: '40.0000',
    uom: 'CAPSULE',
    expired: false,
    controlled: false,
  },
  {
    balanceId: 'bal-later',
    itemId: 'item-1',
    itemCode: 'CEF-1G',
    itemName: 'Ceftriaxone 1 g',
    locationId: 'location-2',
    locationCode: 'MAIN-WH',
    batchId: 'batch-later',
    batchNumber: 'CEF-LATER',
    expiryDate: '2027-01-02',
    daysRemaining: 75,
    onHand: '6.0000',
    uom: 'VIAL',
    expired: false,
    controlled: false,
  },
];

const pendingRemoval: StockAdjustment = {
  adjustmentId: 'adj-expiry',
  itemId: 'item-1',
  locationId: 'location-1',
  batchId: 'batch-exp',
  direction: 'DECREASE',
  quantity: '10.0000',
  reasonCode: 'EXPIRY',
  reasonText: 'Destroyed',
  witnessName: null,
  status: 'PENDING_APPROVAL',
  requestedByUserId: 'user-1',
  requestedAt: '2026-10-17T09:00:00+00:00',
  approvedByUserId: null,
  approvedAt: null,
  rejectionReason: null,
  resultingMovementId: null,
};

const baseProps = {
  items: [],
  locations: [
    { id: 'location-1', code: 'PHARMACY', name: 'Pharmacy Store', locationType: 'STORE', active: true },
    { id: 'location-2', code: 'MAIN-WH', name: 'Main Warehouse', locationType: 'WAREHOUSE', active: true },
  ],
  locationTypes: [],
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
  itemTypes: ['MEDICATION'],
  itemCategories: ['ANTIBIOTIC'],
  unitsOfMeasure: ['VIAL', 'CAPSULE'],
  errors: {},
  status: null,
};

function renderExpiry(overrides: Partial<InventoryWorkspaceProps> = {}): void {
  render(
    <InventoryWorkspace
      {...baseProps}
      currentSection="expiry"
      expiringStock={expiring}
      expiryWindowDays={90}
      adjustments={[]}
      {...overrides}
    />,
  );
}

describe('Inventory expiry worklist', () => {
  it('lists expired and near-expiry batches with day counts and summary counts', () => {
    renderExpiry();

    expect(screen.getByRole('region', { name: 'Expiry' })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Expiring stock (3 of 3)' })).toBeInTheDocument();

    const summary = screen.getByRole('region', { name: 'Expiry summary' });
    expect(within(summary).getByText('Expired')).toBeInTheDocument();
    expect(within(summary).getAllByText('1')).toHaveLength(2);
    expect(within(summary).getByText('Expiring within 30 days')).toBeInTheDocument();
    expect(within(summary).getByText('Inside the 90-day window')).toBeInTheDocument();

    const expiredRow = screen.getByRole('row', { name: /CEF-EXP/ });
    expect(within(expiredRow).getByText('CEF-1G — Ceftriaxone 1 g')).toBeInTheDocument();
    expect(within(expiredRow).getByText('PHARMACY — Pharmacy Store')).toBeInTheDocument();
    expect(within(expiredRow).getByText('10.0000 VIAL')).toBeInTheDocument();
    expect(within(expiredRow).getByText('Expired 3 days ago')).toBeInTheDocument();
    expect(within(expiredRow).getByText('16/10/2026')).toBeInTheDocument();

    const soonRow = screen.getByRole('row', { name: /AMOX-SOON/ });
    expect(within(soonRow).getByText('In 12 days')).toBeInTheDocument();

    const laterRow = screen.getByRole('row', { name: /CEF-LATER/ });
    expect(within(laterRow).getByText('In 75 days')).toBeInTheDocument();
    expect(within(laterRow).getByText('MAIN-WH — Main Warehouse')).toBeInTheDocument();
  });

  it('filters to expired batches only', () => {
    renderExpiry();

    fireEvent.click(screen.getByRole('button', { name: /Filter expiry/ }));
    const expiredFilter = screen.getAllByRole('menuitem', { hidden: true })
      .find((item) => item.textContent?.includes('Expired (1)'));
    if (!expiredFilter) throw new Error('Expired filter missing');
    fireEvent.click(expiredFilter);

    expect(screen.getByRole('row', { name: /CEF-EXP/ })).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /AMOX-SOON/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /CEF-LATER/ })).not.toBeInTheDocument();
  });

  it('searches by item code, name or batch', () => {
    renderExpiry();

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search expiring stock' }), {
      target: { value: 'amox' },
    });

    expect(screen.getByRole('row', { name: /AMOX-SOON/ })).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /CEF-EXP/ })).not.toBeInTheDocument();
  });

  it('requests a whole-batch removal through the existing adjustment route', () => {
    formState.posted.length = 0;
    renderExpiry({ canRequestAdjustment: true });

    const expiredRow = screen.getByRole('row', { name: /CEF-EXP/ });
    fireEvent.click(within(expiredRow).getByRole('button', { name: 'Remove batch' }));

    const dialog = screen.getByRole('dialog', { name: 'Remove CEF-EXP' });
    expect(within(dialog).getByText('CEF-1G — Ceftriaxone 1 g')).toBeInTheDocument();
    expect((within(dialog).getByLabelText('Quantity to remove') as HTMLInputElement).value).toBe('10.0000 VIAL');
    expect(within(dialog).getByText(/Nothing leaves stock yet/)).toBeInTheDocument();

    fireEvent.change(within(dialog).getByLabelText('Note (optional)'), {
      target: { value: 'Incinerated; witnessed by A. Nurse.' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Request removal' }));

    expect(formState.posted).toHaveLength(1);
    const posted = formState.posted[0];
    if (posted === undefined) throw new Error('Removal request was not submitted');
    expect(posted.url).toBe('/workspaces/inventory/adjustments');
    expect(posted.data).toMatchObject({
      item_id: 'item-1',
      location_id: 'location-1',
      direction: 'DECREASE',
      quantity: '10.0000',
      reason_code: 'EXPIRY',
      batch_number: 'CEF-EXP',
      reason_text: 'Incinerated; witnessed by A. Nurse.',
    });
    expect(String(posted.data.adjustment_id)).toMatch(/^[0-9a-f-]{36}$/);
    expect(String(posted.data.idempotency_key)).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('shows an already-requested removal instead of offering a duplicate', () => {
    renderExpiry({ canRequestAdjustment: true, adjustments: [pendingRemoval] });

    const expiredRow = screen.getByRole('row', { name: /CEF-EXP/ });
    expect(within(expiredRow).getByText('Removal requested')).toBeInTheDocument();
    expect(within(expiredRow).queryByRole('button', { name: 'Remove batch' })).not.toBeInTheDocument();

    const soonRow = screen.getByRole('row', { name: /AMOX-SOON/ });
    expect(within(soonRow).getByRole('button', { name: 'Remove batch' })).toBeInTheDocument();
  });

  it('hides removal without the create-adjustment permission', () => {
    renderExpiry({ canRequestAdjustment: false });

    expect(screen.queryByRole('button', { name: 'Remove batch' })).not.toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Expiring stock (3 of 3)' })).toBeInTheDocument();
  });

  it('explains that expiry is facility-specific in General context', () => {
    renderExpiry({ contextMode: 'general' });

    const region = screen.getByRole('region', { name: 'Expiry' });
    expect(within(region).getByText('Select a facility')).toBeInTheDocument();
    expect(screen.queryByRole('table', { name: /Expiring stock/ })).not.toBeInTheDocument();
  });

  it('shows an honest empty state when nothing expires in the window', () => {
    renderExpiry({ expiringStock: [] });

    expect(screen.getByText('No batch with stock on hand expires in the next 90 days.')).toBeInTheDocument();

    const expiredCard = screen.getByText('Expired').closest('article');
    if (!expiredCard) throw new Error('Expired summary card missing');
    expect(within(expiredCard).getByText('0')).toBeInTheDocument();
  });

  it('requires a witness before removing controlled stock', () => {
    formState.posted.length = 0;
    const controlled: ExpiringStock = {
      ...expiring[0]!,
      itemCode: 'MORPH-10',
      itemName: 'Morphine 10 mg injection',
      batchNumber: 'MORPH-EXP',
      controlled: true,
    };
    renderExpiry({ canRequestAdjustment: true, expiringStock: [controlled] });

    fireEvent.click(
      within(screen.getByRole('row', { name: /MORPH-EXP/ })).getByRole('button', { name: 'Remove batch' }),
    );

    const dialog = screen.getByRole('dialog', { name: 'Remove MORPH-EXP' });
    const submit = within(dialog).getByRole('button', { name: 'Request removal' });
    expect(within(dialog).getByLabelText('Witness (required for controlled medicine)')).toBeInTheDocument();
    expect(submit).toBeDisabled();

    fireEvent.change(within(dialog).getByLabelText('Witness (required for controlled medicine)'), {
      target: { value: 'A. Nurse' },
    });
    expect(submit).toBeEnabled();
    fireEvent.click(submit);

    expect(formState.posted).toHaveLength(1);
    expect(formState.posted[0]?.data).toMatchObject({
      reason_code: 'EXPIRY',
      batch_number: 'MORPH-EXP',
      witness_name: 'A. Nurse',
    });
  });

  it('does not ask for a witness when the expired item is not controlled', () => {
    renderExpiry({ canRequestAdjustment: true });

    fireEvent.click(
      within(screen.getByRole('row', { name: /CEF-EXP/ })).getByRole('button', { name: 'Remove batch' }),
    );

    const dialog = screen.getByRole('dialog', { name: 'Remove CEF-EXP' });
    expect(within(dialog).queryByLabelText('Witness (required for controlled medicine)')).not.toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Request removal' })).toBeEnabled();
  });
});
