import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
  ProcurementWorkspaceProps,
  PurchaseRequisition,
} from '@/features/procurement';
import ProcurementIndex from '@/pages/Procurement/Index';

const { formPost, formPatch, routerPost, routerGet } = vi.hoisted(() => ({
  formPost: vi.fn(),
  formPatch: vi.fn(),
  routerPost: vi.fn(),
  routerGet: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');
  return {
    router: { get: routerGet, post: routerPost },
    usePage: () => ({
      props: {
        errors: {},
        auth: {
          facilities: [],
          activeFacility: 'facility-1',
          contextMode: 'facility',
          canUseGeneral: false,
        },
      },
    }),
    useForm: (initialData: Record<string, unknown>) => {
      const [data, setState] = useState(initialData);
      return {
        data,
        errors: {},
        processing: false,
        setData: (keyOrData: string | Record<string, unknown>, value?: unknown) => {
          if (typeof keyOrData === 'string') {
            setState((current) => ({ ...current, [keyOrData]: value }));
            return;
          }
          setState((current) => ({ ...current, ...keyOrData }));
        },
        clearErrors: vi.fn(),
        reset: vi.fn(),
        post: (url: string, options: Record<string, unknown>) => formPost(url, data, options),
        patch: (url: string, options: Record<string, unknown>) => formPatch(url, data, options),
      };
    },
  };
});

vi.mock('@/layouts/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

function requisition(overrides: Partial<PurchaseRequisition>): PurchaseRequisition {
  return {
    id: 'req-1',
    requisitionNumber: 'PR-00000001',
    departmentRef: 'dept-lab',
    departmentName: 'Laboratory',
    status: 'DRAFT',
    priority: 'ROUTINE',
    reason: 'Monthly reagents',
    costCentreRef: null,
    budgetRef: null,
    estimatedValue: null,
    emergency: false,
    emergencyReason: null,
    reviewedByUserId: null,
    reviewedAt: null,
    rejectionReason: null,
    createdByUserId: 'user-1',
    createdAt: '2026-10-02T08:00:00+00:00',
    hasActivePurchaseOrder: false,
    allowedActions: ['submit', 'edit', 'cancel'],
    lines: [
      {
        id: 'line-1',
        lineNo: 1,
        itemRef: 'item-1',
        itemCode: 'GLU-100',
        itemName: 'Glucose strips',
        description: null,
        quantity: '20.0000',
        uom: 'TABLET',
        unit: 'TABLET',
        packSize: null,
        requiredDate: null,
        status: 'OPEN',
      },
    ],
    ...overrides,
  };
}

const requests: PurchaseRequisition[] = [
  requisition({}),
  requisition({
    id: 'req-2',
    requisitionNumber: 'PR-00000002',
    status: 'SUBMITTED',
    createdByUserId: 'user-9',
    allowedActions: ['approve', 'reject', 'cancel'],
  }),
  requisition({
    id: 'req-3',
    requisitionNumber: 'PR-00000003',
    status: 'SUBMITTED',
    createdByUserId: 'user-1',
    allowedActions: ['cancel'],
  }),
  requisition({
    id: 'req-4',
    requisitionNumber: 'PR-00000004',
    status: 'APPROVED',
    createdByUserId: 'user-9',
    allowedActions: ['order'],
  }),
  requisition({
    id: 'req-6',
    requisitionNumber: 'PR-00000006',
    status: 'APPROVED',
    hasActivePurchaseOrder: true,
    createdByUserId: 'user-9',
    allowedActions: [],
  }),
  requisition({
    id: 'req-5',
    requisitionNumber: 'PR-00000005',
    status: 'REJECTED',
    createdByUserId: 'user-1',
    rejectionReason: 'Out of budget this quarter',
    allowedActions: [],
  }),
];

const props: ProcurementWorkspaceProps = {
  section: 'requests',
  facilityContextRequired: false,
  suppliers: [],
  supplierStatuses: ['ACTIVE', 'SUSPENDED', 'INACTIVE', 'BLACKLISTED'],
  canManageSuppliers: false,
  canCreateOrders: true,
  canCreateRequests: true,
  canCreateForAnyDepartment: true,
  currentUserId: 'user-1',
  purchaseOrders: [],
  purchaseRequisitions: requests,
  departments: [{ id: 'dept-lab', name: 'Laboratory', code: 'LAB' }],
  items: [
    {
      id: 'item-1',
      code: 'GLU-100',
      name: 'Glucose strips',
      uom: 'TABLET',
      packUnit: 'BOX',
      packSize: '100',
      category: 'CONSUMABLE',
      itemType: 'SUPPLY',
    },
  ],
  effectiveApprovalLimit: null,
  initialRequest: null,
  orderableRequests: [],
};

function rowFor(requisitionNumber: string): HTMLElement {
  const row = screen.getByText(requisitionNumber).closest('tr');

  if (!(row instanceof HTMLElement)) {
    throw new Error(`No table row for ${requisitionNumber}`);
  }

  return row;
}

describe('procurement requests screen', () => {
  beforeEach(() => {
    formPost.mockReset();
    formPatch.mockReset();
    routerPost.mockReset();
    routerGet.mockReset();

    globalThis.ResizeObserver ??= class {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    };
  });

  it('lists the requests with their department and status', () => {
    render(<ProcurementIndex {...props} />);

    // No persistent section header/alert; the table title ("Requests (5 of 5)")
    // carries the section name.
    expect(within(rowFor('PR-00000001')).getByText('Laboratory')).toBeInTheDocument();
    expect(within(rowFor('PR-00000001')).getByText('Draft')).toBeInTheDocument();
    expect(within(rowFor('PR-00000002')).getByText('Submitted')).toBeInTheDocument();
    expect(within(rowFor('PR-00000004')).getByText('Approved')).toBeInTheDocument();
    expect(within(rowFor('PR-00000005')).getByText('Rejected')).toBeInTheDocument();
  });

  it('offers a status-filtered Excel export and a printable PDF for each request', () => {
    render(<ProcurementIndex {...props} />);

    const exportLink = screen.getByRole('link', { name: 'Export Excel' });
    expect(exportLink).toHaveAttribute('href', '/workspaces/procurement/requests/export.xlsx');

    fireEvent.click(screen.getByRole('button', { name: 'Expand PR-00000001' }));
    const printLink = screen.getByRole('link', { name: 'Print / save PDF' });
    expect(printLink).toHaveAttribute('href', '/workspaces/procurement/requests/req-1/pdf');
    expect(printLink).toHaveAttribute('target', '_blank');

    fireEvent.click(screen.getByRole('button', { name: 'Filter by status: All statuses' }));
    const submittedItem = screen.getAllByRole('menuitem', { hidden: true })
      .find((item) => item.textContent?.includes('Submitted'));
    if (!submittedItem) {
      throw new Error('Submitted status filter missing');
    }
    fireEvent.click(submittedItem);
    expect(exportLink).toHaveAttribute(
      'href',
      '/workspaces/procurement/requests/export.xlsx?status=SUBMITTED',
    );
  });

  it('offers only the actions the server allows on each request', () => {
    render(<ProcurementIndex {...props} />);

    expect(within(rowFor('PR-00000001')).getByRole('button', { name: 'Submit' })).toBeInTheDocument();
    expect(within(rowFor('PR-00000001')).getByRole('button', { name: 'Edit draft' })).toBeInTheDocument();
    expect(within(rowFor('PR-00000001')).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();

    expect(within(rowFor('PR-00000002')).getByRole('button', { name: 'Approve' })).toBeInTheDocument();
    expect(within(rowFor('PR-00000002')).getByRole('button', { name: 'Reject' })).toBeInTheDocument();

    // The person who raised it never gets approve or reject, at any amount.
    expect(within(rowFor('PR-00000003')).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(within(rowFor('PR-00000003')).queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();

    expect(within(rowFor('PR-00000004')).getByRole('button', { name: 'Create purchase order' })).toBeInTheDocument();
    expect(within(rowFor('PR-00000006')).queryByRole('button', { name: 'Create purchase order' })).not.toBeInTheDocument();
    expect(within(rowFor('PR-00000006')).getByText('Purchase order created')).toBeInTheDocument();
    expect(within(rowFor('PR-00000005')).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(within(rowFor('PR-00000005')).queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
  });

  it('posts a transition to the matching route', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(within(rowFor('PR-00000002')).getByRole('button', { name: 'Approve' }));

    expect(routerPost).toHaveBeenCalledWith(
      '/workspaces/procurement/requests/req-2/approve',
      {},
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('opens the order screen prefilled from an approved request', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(within(rowFor('PR-00000004')).getByRole('button', { name: 'Create purchase order' }));

    expect(routerGet).toHaveBeenCalledWith(
      '/workspaces/procurement?section=orders&request=req-4',
      {},
      expect.objectContaining({ preserveState: false }),
    );
  });

  it('explains that the requester cannot approve their own request', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Expand PR-00000003' }));

    expect(screen.getByText(/someone else has to approve or reject it/i)).toBeInTheDocument();
  });

  it('raises a request from the panel', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'New request' }));

    const panel = screen.getByRole('dialog', { name: 'New request' });
    expect(panel).toHaveAttribute('aria-hidden', 'false');
    const expandButton = within(panel).getByRole('button', { name: 'Expand New request' });
    expect(expandButton).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(expandButton);
    expect(within(panel).getByRole('button', { name: 'Collapse New request' })).toHaveAttribute('aria-expanded', 'true');
    expect(within(panel).getByRole('button', { name: 'Remove line 1' })).toBeDisabled();
    fireEvent.click(within(panel).getByRole('button', { name: 'Add line' }));
    expect(within(panel).getByRole('button', { name: 'Remove line 2' })).toBeEnabled();
    fireEvent.click(within(panel).getByRole('button', { name: 'Remove line 2' }));
    expect(within(panel).getAllByRole('combobox', { name: 'Item' })).toHaveLength(1);
    expect(within(panel).getByRole('button', { name: 'Create draft request' })).toBeDisabled();

    fireEvent.change(within(panel).getByLabelText('Department'), { target: { value: 'dept-lab' } });
    const itemCombo = within(panel).getByRole('combobox', { name: 'Item' });
    fireEvent.change(itemCombo, { target: { value: 'GLU-100' } });
    fireEvent.click(within(panel).getByRole('option', { name: /GLU-100/ }));
    expect(within(panel).getByLabelText('Request in')).toHaveValue('BOX');
    expect(within(panel).getByLabelText('TABLET in one BOX')).toHaveValue(100);
    expect(within(panel).getByText('1 BOX = 100.0000 TABLET requested')).toBeInTheDocument();
    fireEvent.change(within(panel).getByLabelText('Quantity'), { target: { value: '3' } });
    expect(within(panel).getByText('3 BOX = 300.0000 TABLET requested')).toBeInTheDocument();
    fireEvent.change(within(panel).getByLabelText(/What is it for/), {
      target: { value: 'Monthly reagents' },
    });
    fireEvent.change(within(panel).getByLabelText('Cost centre'), { target: { value: 'LAB' } });
    fireEvent.change(within(panel).getByLabelText('Budget reference'), { target: { value: 'FY26-LAB' } });

    fireEvent.click(within(panel).getByRole('button', { name: 'Create draft request' }));

    expect(formPost).toHaveBeenCalledWith(
      '/workspaces/procurement/requests',
      {
        requesting_department_ref: 'dept-lab',
        priority: 'ROUTINE',
        reason: 'Monthly reagents',
        cost_centre_ref: 'LAB',
        budget_ref: 'FY26-LAB',
        lines: [{ item_id: 'item-1', unit: 'BOX', quantity: '3', pack_size: '100' }],
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('imports pasted requisition lines', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'New request' }));
    const panel = screen.getByRole('dialog', { name: 'New request' });

    fireEvent.click(within(panel).getByRole('button', { name: 'Paste lines' }));
    const modal = screen.getByRole('dialog', { name: 'Paste requisition lines' });
    fireEvent.change(within(modal).getByLabelText('Request lines'), {
      target: { value: 'GLU-100, 5\nGLU-100, 2' },
    });
    fireEvent.click(within(modal).getByRole('button', { name: 'Use these lines' }));

    expect(within(panel).getByText('2 of 500 lines')).toBeInTheDocument();
    expect(within(panel).getAllByRole('combobox', { name: 'Item' })).toHaveLength(2);
  });

  it('prefills a draft for editing and patches only that request', () => {
    render(
      <ProcurementIndex
        {...props}
        purchaseRequisitions={requests.map((request) => request.id === 'req-1'
          ? { ...request, costCentreRef: 'LAB-01', budgetRef: 'FY26-LAB-REQ' }
          : request)}
      />,
    );

    fireEvent.click(within(rowFor('PR-00000001')).getByRole('button', { name: 'Edit draft' }));

    const panel = screen.getByRole('dialog', { name: 'Edit PR-00000001' });
    expect(within(panel).getByLabelText('Department')).toHaveValue('dept-lab');
    expect(within(panel).getByLabelText('Priority')).toHaveValue('ROUTINE');
    expect(within(panel).getByLabelText(/What is it for/)).toHaveValue('Monthly reagents');
    expect(within(panel).getByLabelText('Cost centre')).toHaveValue('LAB-01');
    expect(within(panel).getByLabelText('Budget reference')).toHaveValue('FY26-LAB-REQ');
    expect(within(panel).getByLabelText('Quantity')).toHaveValue(20);

    fireEvent.change(within(panel).getByLabelText('Quantity'), { target: { value: '35' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Save draft changes' }));

    expect(formPatch).toHaveBeenCalledWith(
      '/workspaces/procurement/requests/req-1',
      {
        requesting_department_ref: 'dept-lab',
        priority: 'ROUTINE',
        reason: 'Monthly reagents',
        cost_centre_ref: 'LAB-01',
        budget_ref: 'FY26-LAB-REQ',
        lines: [{
          item_id: 'item-1',
          unit: '',
          quantity: '35',
          pack_size: '',
          required_date: '',
        }],
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('locks a single-department requester to their assigned department', () => {
    render(
      <ProcurementIndex
        {...props}
        canCreateForAnyDepartment={false}
        departments={[{ id: 'dept-pharmacy', name: 'Pharmaceutical', code: 'PHD' }]}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'New request' }));
    const panel = screen.getByRole('dialog', { name: 'New request' });
    const department = within(panel).getByLabelText('Department');

    expect(department).toHaveValue('dept-pharmacy');
    expect(department).toBeDisabled();
    expect(within(panel).queryByRole('option', { name: /No department/ })).not.toBeInTheDocument();
  });

  it('offers only the departments authorized for a multi-department requester', () => {
    render(
      <ProcurementIndex
        {...props}
        canCreateForAnyDepartment={false}
        departments={[
          { id: 'dept-lab', name: 'Laboratory', code: 'LAB' },
          { id: 'dept-pharmacy', name: 'Pharmaceutical', code: 'PHD' },
        ]}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'New request' }));
    const panel = screen.getByRole('dialog', { name: 'New request' });
    const department = within(panel).getByLabelText('Department');

    expect(department).toBeEnabled();
    expect(within(department).getByRole('option', { name: /Laboratory/ })).toBeInTheDocument();
    expect(within(department).getByRole('option', { name: /Pharmaceutical/ })).toBeInTheDocument();
    expect(within(department).queryByRole('option', { name: /No department/ })).not.toBeInTheDocument();
  });

  it('rejects a request with a reason', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(within(rowFor('PR-00000002')).getByRole('button', { name: 'Reject' }));

    const panel = screen.getByRole('dialog', { name: 'Reject request' });
    expect(within(panel).getByRole('button', { name: 'Reject request' })).toBeDisabled();

    fireEvent.change(within(panel).getByLabelText(/Reason/), {
      target: { value: 'Order it next month' },
    });
    fireEvent.click(within(panel).getByRole('button', { name: 'Reject request' }));

    expect(formPost).toHaveBeenCalledWith(
      '/workspaces/procurement/requests/req-2/reject',
      { reason: 'Order it next month' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('cancels a draft request after a confirmation', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(within(rowFor('PR-00000001')).getByRole('button', { name: 'Cancel' }));

    const panel = screen.getByRole('dialog', { name: 'Cancel request' });
    fireEvent.click(within(panel).getByRole('button', { name: 'Cancel request' }));

    expect(routerPost).toHaveBeenCalledWith(
      '/workspaces/procurement/requests/req-1/cancel',
      {},
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('hides every write control from a reader', () => {
    render(<ProcurementIndex {...props} canCreateRequests={false} />);

    expect(screen.queryByRole('button', { name: 'New request' })).not.toBeInTheDocument();
  });
});
