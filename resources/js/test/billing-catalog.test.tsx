import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BillingCatalog from '@/pages/Billing/Catalog';

const form = vi.hoisted(() => ({
  post: vi.fn(),
  transform: vi.fn(),
  setData: vi.fn(),
  clearErrors: vi.fn(),
  reset: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
  usePage: () => ({ props: { errors: {}, flash: { status: null } } }),
  useForm: () => ({
    // A fully-populated, valid form so panels are submitted as-is in the test.
    data: {
      code: 'CONSULT',
      name: 'Consultation',
      category: 'CONSULTATION',
      amount: '1000.00',
      effective_from: '2026-01-01',
      effective_to: '2026-06-01',
      facility_scope: true,
      item_id: 'item-1',
      billing_service_id: 'svc-1',
    },
    errors: {},
    processing: false,
    setData: form.setData,
    post: form.post,
    transform: form.transform,
    clearErrors: form.clearErrors,
    reset: form.reset,
  }),
}));

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const baseProps = {
  facilityId: 'facility-1',
  canManageCatalog: true,
  canRetryCharges: true,
  services: [
    {
      billingServiceId: 'svc-1',
      code: 'MED1',
      name: 'Paracetamol 500 mg',
      category: 'MEDICATION',
      status: 'ACTIVE',
      amount: '5000.00',
      resolvedScope: 'FACILITY',
      currency: 'TZS',
      effectiveFrom: '2026-01-01',
    },
    {
      billingServiceId: 'svc-2',
      code: 'CONSULT',
      name: 'General consultation',
      category: 'CONSULTATION',
      status: 'INACTIVE',
      amount: null,
      resolvedScope: 'NONE',
      currency: 'TZS',
      effectiveFrom: null,
    },
  ],
  category: null,
  status: null,
  selectedServiceId: 'svc-1',
  selectedCode: 'MED1',
  selectedName: 'Paracetamol 500 mg',
  selectedCategory: 'MEDICATION',
  selectedStatus: 'ACTIVE',
  facilityAmount: '5000.00',
  facilityEffectiveFrom: '2026-01-01',
  defaultAmount: null,
  defaultEffectiveFrom: null,
  currency: 'TZS',
  pendingEvents: [
    {
      billableEventId: 'evt-1',
      patientDisplayName: 'Amina Juma',
      patientNumber: 'PAT-0001',
      quantity: '10',
      pendingReason: 'ITEM_UNMAPPED',
      occurredAt: '2026-10-14T20:00:00Z',
    },
  ],
  tariffHistory: [
    {
      tariffId: 'tar-1',
      resolvedScope: 'FACILITY',
      amount: '5000.00',
      currency: 'TZS',
      effectiveFrom: '2026-01-01',
      effectiveTo: null,
      status: 'CURRENT',
      current: true,
    },
  ],
  medicationMaps: [
    {
      invItemId: 'item-1',
      code: 'PARA-500',
      name: 'Paracetamol 500 mg',
      itemType: 'MEDICATION',
      itemActive: true,
      mapped: false,
      billingServiceId: null,
      serviceCode: null,
      serviceName: null,
      serviceStatus: null,
      amount: null,
      resolvedScope: null,
      currency: null,
      mappedAt: null,
    },
    {
      invItemId: 'item-2',
      code: 'AMOX-250',
      name: 'Amoxicillin 250 mg',
      itemType: 'MEDICATION',
      itemActive: true,
      mapped: true,
      billingServiceId: 'svc-1',
      serviceCode: 'MED1',
      serviceName: 'Paracetamol 500 mg',
      serviceStatus: 'ACTIVE',
      amount: '5000.00',
      resolvedScope: 'FACILITY',
      currency: 'TZS',
      mappedAt: '2026-10-14T19:00:00Z',
    },
  ],
  itemMapHistory: [
    {
      mapId: 'map-1',
      invItemId: 'item-2',
      billingServiceId: 'svc-1',
      serviceName: 'Paracetamol 500 mg',
      active: true,
      occurredAt: '2026-10-14T19:00:00Z',
    },
  ],
  searchError: null,
};

describe('billing catalog workspace', () => {
  beforeEach(() => {
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

  it('shows the price list with the effective facility tariff', () => {
    render(<BillingCatalog {...baseProps} />);

    expect(screen.getByText('MED1')).toBeInTheDocument();
    expect(screen.getAllByText('5000.00 TZS').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Facility override').length).toBeGreaterThan(0);
  });

  it('requests a filtered price list when the category filter changes', () => {
    render(<BillingCatalog {...baseProps} />);

    fireEvent.change(screen.getByLabelText('Category filter'), { target: { value: 'MEDICATION' } });

    expect(router.get).toHaveBeenCalledWith(
      '/workspaces/billing/catalog',
      expect.objectContaining({ category: 'MEDICATION', service_id: 'svc-1' }),
      expect.objectContaining({ only: expect.arrayContaining(['services', 'tariffHistory']) }),
    );
  });

  it('reloads with the selected service when Inspect is chosen', () => {
    render(<BillingCatalog {...baseProps} />);

    const row = screen.getByText('General consultation').closest('tr');
    expect(row).not.toBeNull();
    fireEvent.click(within(row as HTMLElement).getByRole('button', { name: 'Inspect' }));

    expect(router.get).toHaveBeenCalledWith(
      '/workspaces/billing/catalog',
      expect.objectContaining({ service_id: 'svc-2' }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('maps an unmapped medication through the docked panel', () => {
    render(<BillingCatalog {...baseProps} />);

    fireEvent.click(screen.getByRole('tab', { name: 'Medication mapping' }));
    expect(screen.getByText('Not mapped')).toBeInTheDocument();

    fireEvent.click(screen.getAllByRole('button', { name: 'Map' })[0]!);
    expect(screen.getByText('Map medication to a service')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Save mapping' }));
    expect(form.post).toHaveBeenCalledWith(
      '/workspaces/billing/catalog/item-maps',
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('removes a mapping and retries a pending charge', () => {
    render(<BillingCatalog {...baseProps} />);

    fireEvent.click(screen.getByRole('tab', { name: 'Medication mapping' }));
    fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/billing/catalog/item-maps/deactivate',
      { item_id: 'item-2' },
      { preserveScroll: true },
    );

    fireEvent.click(screen.getByRole('tab', { name: /Pending charges/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Retry charge' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/billing/catalog/events/evt-1/retry',
      {},
      { preserveScroll: true },
    );
  });

  it('disables retry and mapping when the caller holds no write keys', () => {
    render(<BillingCatalog {...baseProps} canManageCatalog={false} canRetryCharges={false} />);

    fireEvent.click(screen.getByRole('tab', { name: 'Medication mapping' }));
    expect(screen.getAllByRole('button', { name: 'Map' })[0]).toBeDisabled();

    fireEvent.click(screen.getByRole('tab', { name: /Pending charges/ }));
    expect(screen.getByRole('button', { name: 'Retry charge' })).toBeDisabled();
  });
});
