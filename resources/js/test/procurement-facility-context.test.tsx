import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ProcurementWorkspaceProps } from '@/features/procurement';
import ProcurementIndex from '@/pages/Procurement/Index';

const state = vi.hoisted(() => ({
  post: vi.fn(),
  visit: vi.fn(),
  auth: {
    facilities: [
      { id: 'facility-1', name: 'Central Clinic' },
      { id: 'facility-2', name: 'West Clinic' },
    ],
    activeFacility: null as string | null,
    contextMode: 'general' as 'general' | 'facility' | 'unresolved',
    canUseGeneral: true,
  },
}));

vi.mock('@inertiajs/react', () => ({
  router: { post: state.post, visit: state.visit },
  usePage: () => ({ props: { auth: state.auth } }),
}));

vi.mock('@/features/procurement', () => ({
  OrdersSection: () => null,
  RequestsSection: () => null,
  SuppliersSection: () => null,
}));

vi.mock('@/layouts/AppLayout', () => ({
  AppLayout: ({
    children,
    onSidebarItemClick,
    activeSidebarItemId,
  }: {
    children: React.ReactNode;
    onSidebarItemClick?: (item: { id: string; label: string; href: string; active: boolean }, event: React.MouseEvent) => void;
    activeSidebarItemId?: string;
  }) => (
    <>
      <button
        type="button"
        onClick={(event) => onSidebarItemClick?.({
          id: 'procurement-orders',
          label: 'Purchase orders',
          href: '/workspaces/procurement?section=orders',
          active: false,
        }, event)}
      >
        Open purchase orders
      </button>
      <span>{activeSidebarItemId}</span>
      {children}
    </>
  ),
}));

const props: ProcurementWorkspaceProps = {
  section: 'suppliers',
  facilityContextRequired: false,
  suppliers: [],
  supplierStatuses: [],
  canManageSuppliers: false,
  canCreateOrders: false,
  canCreateRequests: false,
  canCreateForAnyDepartment: false,
  currentUserId: null,
  purchaseOrders: [],
  purchaseRequisitions: [],
  departments: [],
  items: [],
  effectiveApprovalLimit: null,
  initialRequest: null,
  orderableRequests: [],
};

describe('Procurement facility context selection', () => {
  beforeEach(() => {
    state.post.mockReset();
    state.visit.mockReset();
    state.auth.activeFacility = null;
    state.auth.contextMode = 'general';
    globalThis.ResizeObserver = class ResizeObserver {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    };
  });

  it('selects an authorized facility, switches context, then opens the requested section', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Open purchase orders' }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    fireEvent.click(screen.getByLabelText('West Clinic'));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

    expect(state.post).toHaveBeenCalledWith(
      '/facility-context',
      { facility_id: 'facility-2' },
      expect.objectContaining({ onSuccess: expect.any(Function) }),
    );

    const options = state.post.mock.calls[0]?.[2];
    if (typeof options !== 'object' || options === null || !('onSuccess' in options)) {
      throw new Error('Expected a facility-switch success callback.');
    }
    if (typeof options.onSuccess !== 'function') {
      throw new Error('Expected the facility-switch success callback to be callable.');
    }
    act(() => options.onSuccess());

    expect(state.visit).toHaveBeenCalledWith('/workspaces/procurement?section=orders');
  });

  it('opens the facility picker automatically for a direct facility-section link', () => {
    render(<ProcurementIndex {...props} section="requests" facilityContextRequired />);

    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText(/Procurement requests belong to a facility/)).toBeInTheDocument();
  });
});
