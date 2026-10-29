import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ProcurementWorkspaceProps, PurchaseOrder } from '@/features/procurement';
import ProcurementIndex from '@/pages/Procurement/Index';

const { formPost, routerPost } = vi.hoisted(() => ({
  formPost: vi.fn(),
  routerPost: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');
  return {
    router: { get: vi.fn(), post: routerPost },
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
      };
    },
  };
});

vi.mock('@/layouts/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const suppliers: ProcurementWorkspaceProps['suppliers'] = [
  {
    id: 'sup-1',
    code: 'PHARMA-01',
    name: 'Acme Medical',
    registrationNumber: null,
    licenceNumber: null,
    licenceAuthority: null,
    licenceExpiryDate: null,
    licenceExpiryStatus: 'MISSING',
    contactName: null,
    contactEmail: null,
    contactPhone: null,
    address: null,
    taxIdentifier: null,
    paymentTerms: null,
    categories: [],
    status: 'ACTIVE',
    statusReason: null,
    allowedStatuses: ['SUSPENDED'],
  },
];

function order(overrides: Partial<PurchaseOrder>): PurchaseOrder {
  return {
    id: 'po-1',
    poNumber: 'PO-0001',
    supplierId: 'sup-1',
    requisitionId: null,
    sourcingMethod: 'DIRECT',
    status: 'DRAFT',
    currency: 'TZS',
    priority: 'ROUTINE',
    emergency: false,
    orderTotal: '1000.00',
    notes: null,
    costCentreRef: null,
    budgetRef: null,
    createdByUserId: 'user-1',
    approvedByUserId: null,
    issuedByUserId: null,
    cancellationReason: null,
    closedShortReason: null,
    closedShortAt: null,
    createdAt: '2026-10-01T09:00:00+00:00',
    amendmentCount: 0,
    selfApprovalPermitted: true,
    allowedActions: ['submit', 'cancel'],
    lines: [
      {
        id: 'line-1',
        lineNo: 1,
        itemCode: 'PARA-500',
        itemName: 'Paracetamol 500mg',
        description: null,
        orderedQty: '10.0000',
        unit: 'TABLET',
        packSize: null,
        orderedBaseQty: '10.0000',
        receivedQty: '0.0000',
        uom: 'TABLET',
        unitPrice: '100.00',
        lineTotal: '1000.00',
        status: 'ORDERED',
      },
    ],
    ...overrides,
  };
}

const orders: PurchaseOrder[] = [
  order({}),
  order({
    id: 'po-2',
    poNumber: 'PO-0002',
    status: 'SUBMITTED',
    orderTotal: '250000.00',
    createdByUserId: 'user-9',
    selfApprovalPermitted: false,
    allowedActions: ['approve', 'cancel'],
  }),
  order({
    id: 'po-3',
    poNumber: 'PO-0003',
    status: 'SUBMITTED',
    orderTotal: '400000.00',
    createdByUserId: 'user-1',
    selfApprovalPermitted: false,
    allowedActions: ['cancel'],
  }),
  order({
    id: 'po-4',
    poNumber: 'PO-0004',
    status: 'ISSUED',
    orderTotal: '90000.00',
    approvedByUserId: 'user-9',
    issuedByUserId: 'user-9',
    allowedActions: ['cancel'],
  }),
];

const props: ProcurementWorkspaceProps = {
  section: 'orders',
  facilityContextRequired: false,
  suppliers,
  supplierStatuses: ['ACTIVE', 'SUSPENDED', 'INACTIVE', 'BLACKLISTED'],
  canManageSuppliers: false,
  canCreateOrders: true,
  canCreateRequests: false,
  canCreateForAnyDepartment: false,
  currentUserId: 'user-1',
  purchaseOrders: orders,
  purchaseRequisitions: [],
  departments: [],
  items: [
    {
      id: 'item-1',
      code: 'PARA-500',
      name: 'Paracetamol 500mg',
      uom: 'TABLET',
      packUnit: null,
      packSize: null,
      category: 'ANALGESIC',
      itemType: 'MEDICATION',
    },
    {
      id: 'item-2',
      code: 'AMOX-500',
      name: 'Amoxicillin 500mg',
      uom: 'CAPSULE',
      packUnit: 'BOX',
      packSize: '100',
      category: 'ANTIBIOTIC',
      itemType: 'MEDICATION',
    },
  ],
  effectiveApprovalLimit: '1000000.00',
  initialRequest: null,
  orderableRequests: [],
};

function rowFor(poNumber: string): HTMLElement {
  const row = screen.getByText(poNumber).closest('tr');

  if (!(row instanceof HTMLElement)) {
    throw new Error(`No table row for ${poNumber}`);
  }

  return row;
}

describe('procurement purchase orders screen', () => {
  beforeEach(() => {
    formPost.mockReset();
    routerPost.mockReset();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        data: {
          currency: 'TZS',
          lastSupplierPrice: null,
          lastAnySupplierPrice: null,
          contractPrice: null,
        },
      }),
    }));

    globalThis.ResizeObserver ??= class {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    };
  });

  it('lists the orders and their suppliers', () => {
    render(<ProcurementIndex {...props} />);

    expect(screen.getByRole('heading', { name: 'Purchase orders' })).toBeInTheDocument();

    expect(within(rowFor('PO-0001')).getByText('Draft')).toBeInTheDocument();
    expect(within(rowFor('PO-0001')).getByText('Acme Medical')).toBeInTheDocument();
    expect(within(rowFor('PO-0001')).getByText('TZS 1,000.00')).toBeInTheDocument();
    expect(within(rowFor('PO-0002')).getByText('Submitted')).toBeInTheDocument();
    expect(within(rowFor('PO-0004')).getByText('Issued')).toBeInTheDocument();
  });

  it('offers a status-filtered Excel export and a printable PDF for each order', () => {
    render(<ProcurementIndex {...props} />);

    const exportLink = screen.getByRole('link', { name: 'Export Excel' });
    expect(exportLink).toHaveAttribute('href', '/workspaces/procurement/orders/export.xlsx');

    fireEvent.click(screen.getByRole('button', { name: 'Expand PO-0001' }));
    const printLink = screen.getByRole('link', { name: 'Print / save PDF' });
    expect(printLink).toHaveAttribute('href', '/workspaces/procurement/orders/po-1/pdf');
    expect(printLink).toHaveAttribute('target', '_blank');

    fireEvent.click(screen.getByRole('button', { name: 'Filter by status: All statuses' }));
    const issuedItem = screen.getAllByRole('menuitem', { hidden: true })
      .find((item) => item.textContent?.includes('Issued'));
    if (!issuedItem) {
      throw new Error('Issued status filter missing');
    }
    fireEvent.click(issuedItem);
    expect(exportLink).toHaveAttribute(
      'href',
      '/workspaces/procurement/orders/export.xlsx?status=ISSUED',
    );
  });

  it('shows the cost-centre references on the order and captures them on a new order', () => {
    render(
      <ProcurementIndex
        {...props}
        purchaseOrders={[order({ costCentreRef: 'PHARMACY', budgetRef: 'FY26-MED-01' })]}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Expand PO-0001' }));
    expect(screen.getByText('Cost centre:').parentElement).toHaveTextContent('PHARMACY');
    expect(screen.getByText('Budget reference:').parentElement).toHaveTextContent('FY26-MED-01');

    fireEvent.click(screen.getByRole('button', { name: 'New purchase order' }));
    const panel = screen.getByRole('dialog', { name: 'New purchase order' });
    fireEvent.change(within(panel).getByLabelText('Cost centre'), { target: { value: 'LAB' } });
    fireEvent.change(within(panel).getByLabelText('Budget reference'), { target: { value: 'FY26-LAB' } });
    fireEvent.change(within(panel).getByLabelText('Supplier'), { target: { value: 'sup-1' } });
    const itemCombo = within(panel).getByRole('combobox', { name: 'Item' });
    fireEvent.change(itemCombo, { target: { value: 'PARA-500' } });
    fireEvent.click(within(panel).getByRole('option', { name: /PARA-500/ }));
    fireEvent.change(within(panel).getByLabelText('Unit price (TZS)'), { target: { value: '250' } });
    fireEvent.change(within(panel).getByLabelText('Repeat-order reason'), {
      target: { value: 'Replacement order after stock loss' },
    });
    fireEvent.click(within(panel).getByRole('button', { name: 'Create draft order' }));

    expect(within(panel).getByLabelText('Cost centre')).toHaveValue('LAB');
    expect(within(panel).getByLabelText('Budget reference')).toHaveValue('FY26-LAB');
    expect(formPost).toHaveBeenCalledWith(
      '/workspaces/procurement/orders',
      expect.objectContaining({
        cost_centre_ref: 'LAB',
        budget_ref: 'FY26-LAB',
        duplicate_override_reason: 'Replacement order after stock loss',
      }),
      expect.anything(),
    );
  });

  it('loads historical and contract price references and fills an empty price from history', async () => {
    const fetchMock = vi.mocked(globalThis.fetch);
    fetchMock.mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        data: {
          currency: 'TZS',
          lastSupplierPrice: {
            poNumber: 'PO-0042',
            unitPrice: '250.00',
            baseUnitPrice: '250.00',
            unit: 'TABLET',
            uom: 'TABLET',
            packSize: null,
            orderedAt: '2026-10-01',
          },
          lastAnySupplierPrice: null,
          contractPrice: {
            contractNumber: 'CON-0010',
            unitPrice: '240.00',
            uom: 'TABLET',
            remaining: '1000.0000',
          },
        },
      }),
    } as Response);

    render(<ProcurementIndex {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'New purchase order' }));

    const panel = screen.getByRole('dialog', { name: 'New purchase order' });
    fireEvent.change(within(panel).getByLabelText('Supplier'), { target: { value: 'sup-1' } });
    const itemCombo = within(panel).getByRole('combobox', { name: 'Item' });
    fireEvent.change(itemCombo, { target: { value: 'PARA-500' } });
    fireEvent.click(within(panel).getByRole('option', { name: /PARA-500/ }));
    fireEvent.click(within(panel).getByRole('button', { name: 'Check price references' }));

    await waitFor(() => expect(within(panel).getByLabelText('Unit price (TZS)')).toHaveValue(250));
    expect(within(panel).getByText(/Last from this supplier/)).toHaveTextContent('PO-0042');
    expect(within(panel).getByText(/Active contract CON-0010/)).toHaveTextContent('1000.0000 remaining');
    expect(fetchMock).toHaveBeenCalledWith(
      '/workspaces/procurement/orders/item-prices?supplier_id=sup-1&item_id=item-1',
      expect.objectContaining({ credentials: 'same-origin' }),
    );
  });

  it('offers only the actions the server allows on each order', () => {
    render(<ProcurementIndex {...props} />);

    expect(within(rowFor('PO-0001')).getByRole('button', { name: 'Submit for approval' })).toBeInTheDocument();
    expect(within(rowFor('PO-0001')).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();

    expect(within(rowFor('PO-0002')).getByRole('button', { name: 'Approve' })).toBeInTheDocument();
    expect(within(rowFor('PO-0002')).queryByRole('button', { name: 'Submit for approval' })).not.toBeInTheDocument();

    expect(within(rowFor('PO-0003')).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(within(rowFor('PO-0003')).getByRole('button', { name: 'Cancel' })).toBeInTheDocument();

    expect(within(rowFor('PO-0004')).queryByRole('button', { name: 'Issue to supplier' })).not.toBeInTheDocument();
  });

  it('posts a transition to the matching route', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(within(rowFor('PO-0001')).getByRole('button', { name: 'Submit for approval' }));

    expect(routerPost).toHaveBeenCalledWith(
      '/workspaces/procurement/orders/po-1/submit',
      {},
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('explains why the creator has no approve button above the limit', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Expand PO-0003' }));

    expect(screen.getByText(/Someone else has to approve it/)).toBeInTheDocument();
  });

  it('cancels an order with a reason', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(within(rowFor('PO-0001')).getByRole('button', { name: 'Cancel' }));

    const panel = screen.getByRole('dialog', { name: 'Cancel purchase order' });
    expect(panel).toHaveAttribute('aria-hidden', 'false');

    fireEvent.change(within(panel).getByLabelText(/Reason/), {
      target: { value: 'Supplier substituted' },
    });
    fireEvent.click(within(panel).getByRole('button', { name: 'Cancel order' }));

    expect(formPost).toHaveBeenCalledWith(
      '/workspaces/procurement/orders/po-1/cancel',
      { reason: 'Supplier substituted' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('creates a draft order from the panel', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'New purchase order' }));

    const panel = screen.getByRole('dialog', { name: 'New purchase order' });
    expect(panel).toHaveAttribute('aria-hidden', 'false');

    fireEvent.change(within(panel).getByLabelText('Supplier'), { target: { value: 'sup-1' } });
    const itemCombo = within(panel).getByRole('combobox', { name: 'Item' });
    fireEvent.change(itemCombo, { target: { value: 'PARA-500' } });
    fireEvent.click(within(panel).getByRole('option', { name: /PARA-500/ }));
    fireEvent.change(within(panel).getByLabelText('Unit price (TZS)'), {
      target: { value: '250' },
    });

    expect(within(panel).getByText(/Draft total/)).toHaveTextContent('TZS 250.00');

    fireEvent.click(within(panel).getByRole('button', { name: 'Create draft order' }));

    expect(formPost).toHaveBeenCalledWith(
      '/workspaces/procurement/orders',
      expect.objectContaining({
        supplier_id: 'sup-1',
        priority: 'ROUTINE',
        lines: [{ item_id: 'item-1', unit: '', quantity: '1', pack_size: '', unit_price: '250' }],
      }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('imports pasted purchase-order lines', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'New purchase order' }));
    const panel = screen.getByRole('dialog', { name: 'New purchase order' });

    fireEvent.click(within(panel).getByRole('button', { name: 'Paste lines' }));
    const modal = screen.getByRole('dialog', { name: 'Paste purchase-order lines' });
    fireEvent.change(within(modal).getByLabelText('Order lines'), {
      target: { value: 'PARA-500, 100, 50\nAMOX-500, 2, 1000' },
    });
    fireEvent.click(within(modal).getByRole('button', { name: 'Use these lines' }));

    expect(within(panel).getByText(/2 of 500 lines/)).toBeInTheDocument();
    expect(within(panel).getByText(/Draft total/)).toHaveTextContent('TZS 7,000.00');
  });

  it('orders in the buying unit and shows the base quantity it will add', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'New purchase order' }));

    const panel = screen.getByRole('dialog', { name: 'New purchase order' });

    fireEvent.change(within(panel).getByLabelText('Supplier'), { target: { value: 'sup-1' } });
    const itemCombo = within(panel).getByRole('combobox', { name: 'Item' });
    fireEvent.change(itemCombo, { target: { value: 'AMOX-500' } });
    fireEvent.click(within(panel).getByRole('option', { name: /AMOX-500/ }));

    // The item's buying unit is chosen for the buyer, but it is never forced.
    const unit = within(panel).getByLabelText('Order in') as HTMLSelectElement;
    expect(unit).toHaveValue('BOX');
    expect(unit).not.toBeDisabled();

    // The size starts at the item's default and can be corrected for this supplier.
    const packSize = within(panel).getByLabelText('CAPSULE in one BOX') as HTMLInputElement;
    expect(packSize.value).toBe('100');

    fireEvent.change(within(panel).getByLabelText('Quantity'), { target: { value: '3' } });
    expect(within(panel).getByText('3 BOX = 300.0000 CAPSULE in stock')).toBeInTheDocument();

    fireEvent.change(packSize, { target: { value: '50' } });
    expect(within(panel).getByText('3 BOX = 150.0000 CAPSULE in stock')).toBeInTheDocument();

    fireEvent.change(within(panel).getByLabelText('Unit price (TZS)'), {
      target: { value: '10000' },
    });

    // The price is per BOX, never divided into a per-capsule figure.
    expect(within(panel).getByText(/Draft total/)).toHaveTextContent('TZS 30,000.00');

    fireEvent.click(within(panel).getByRole('button', { name: 'Create draft order' }));

    expect(formPost).toHaveBeenCalledWith(
      '/workspaces/procurement/orders',
      expect.objectContaining({
        supplier_id: 'sup-1',
        lines: [{
          item_id: 'item-2',
          unit: 'BOX',
          quantity: '3',
          pack_size: '50',
          unit_price: '10000',
        }],
      }),
      expect.objectContaining({ preserveScroll: true }),
    );

    // Switching back to the base unit drops the pack record.
    fireEvent.change(unit, { target: { value: '' } });
    expect(within(panel).queryByText(/in stock/)).not.toBeInTheDocument();
  });

  it('keeps the create button disabled until the order is complete', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'New purchase order' }));

    const panel = screen.getByRole('dialog', { name: 'New purchase order' });
    expect(within(panel).getByRole('button', { name: 'Create draft order' })).toBeDisabled();

    fireEvent.change(within(panel).getByLabelText('Supplier'), { target: { value: 'sup-1' } });
    expect(within(panel).getByRole('button', { name: 'Create draft order' })).toBeDisabled();

    const itemCombo = within(panel).getByRole('combobox', { name: 'Item' });
    fireEvent.change(itemCombo, { target: { value: 'PARA-500' } });
    fireEvent.click(within(panel).getByRole('option', { name: /PARA-500/ }));
    expect(within(panel).getByRole('button', { name: 'Create draft order' })).toBeDisabled();

    fireEvent.change(within(panel).getByLabelText('Unit price (TZS)'), {
      target: { value: '250' },
    });
    expect(within(panel).getByRole('button', { name: 'Create draft order' })).toBeEnabled();
  });

  it('adds and removes order lines', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'New purchase order' }));

    const panel = screen.getByRole('dialog', { name: 'New purchase order' });
    expect(within(panel).getAllByRole('combobox', { name: 'Item' })).toHaveLength(1);

    fireEvent.click(within(panel).getByRole('button', { name: 'Add line' }));
    expect(within(panel).getAllByRole('combobox', { name: 'Item' })).toHaveLength(2);

    fireEvent.click(within(panel).getByRole('button', { name: 'Remove line 2' }));
    expect(within(panel).getAllByRole('combobox', { name: 'Item' })).toHaveLength(1);
  });

  it('shows eligible requests when the request selector is opened', async () => {
    render(
      <ProcurementIndex
        {...props}
        orderableRequests={[{
          id: 'req-available',
          requisitionNumber: 'PR-00000001',
          departmentRef: 'dept-pharmacy',
          departmentName: 'Pharmaceutical',
          priority: 'ROUTINE',
          reason: null,
          costCentreRef: null,
          budgetRef: null,
          lines: [],
          unorderableLineCount: 0,
        }]}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'New purchase order' }));

    const panel = screen.getByRole('dialog', { name: 'New purchase order' });
    fireEvent.click(within(panel).getByRole('combobox', { name: 'Internal request (optional)' }));

    expect(await within(panel).findByRole('option', { name: 'PR-00000001 — Pharmaceutical' }))
      .toBeInTheDocument();
  });

  it('opens the panel prefilled from an approved request and links the order to it', async () => {
    render(
      <ProcurementIndex
        {...props}
        initialRequest={{
          id: 'req-4',
          requisitionNumber: 'PR-00000004',
          departmentRef: 'dept-lab',
          departmentName: 'Laboratory',
          priority: 'URGENT',
          reason: 'Monthly reagents',
          costCentreRef: 'LAB-01',
          budgetRef: 'FY26-LAB-REQ',
          lines: [
            {
              itemId: 'item-2',
              itemCode: 'AMOX-500',
              itemName: 'Amoxicillin 500mg',
              quantity: '20.0000',
              uom: 'CAPSULE',
              unit: 'BOX',
              packSize: '100',
            },
          ],
          unorderableLineCount: 0,
        }}
      />,
    );

    const panel = screen.getByRole('dialog', { name: 'New purchase order' });
    expect(panel).toHaveAttribute('aria-hidden', 'false');
    expect(within(panel).getByRole('combobox', { name: 'Internal request (optional)' }))
      .toHaveValue('PR-00000004 — Laboratory');
    expect(within(panel).getByText(/Using request PR-00000004/)).toBeInTheDocument();
    expect(within(panel).getByLabelText('Priority')).toHaveValue('URGENT');
    expect(within(panel).getByLabelText('Cost centre')).toHaveValue('LAB-01');
    expect(within(panel).getByLabelText('Budget reference')).toHaveValue('FY26-LAB-REQ');
    expect(within(panel).getByRole('combobox', { name: 'Item' })).toHaveValue('AMOX-500 — Amoxicillin 500mg (CAPSULE)');
    expect(within(panel).getByLabelText(/What is it for|Notes/)).toHaveValue('Monthly reagents');
    expect((within(panel).getByLabelText('Quantity') as HTMLInputElement).value).toContain('20');

    fireEvent.click(within(panel).getAllByRole('button', { name: 'Clear selected item' })[0]!);
    expect(within(panel).getByRole('combobox', { name: 'Internal request (optional)' }))
      .toHaveValue('Direct purchase order — no internal request');
    expect(within(panel).queryByText(/Using request PR-00000004/)).not.toBeInTheDocument();
    fireEvent.change(within(panel).getByRole('combobox', { name: 'Internal request (optional)' }), {
      target: { value: 'PR-00000004' },
    });
    fireEvent.click(await within(panel).findByRole('option', { name: /PR-00000004 — Laboratory/ }));

    fireEvent.change(within(panel).getByLabelText('Supplier'), { target: { value: 'sup-1' } });
    fireEvent.change(within(panel).getByLabelText('Unit price (TZS)'), {
      target: { value: '5000' },
    });
    fireEvent.click(within(panel).getByRole('button', { name: 'Create draft order' }));

    expect(formPost).toHaveBeenCalledWith(
      '/workspaces/procurement/orders',
      expect.objectContaining({
        supplier_id: 'sup-1',
        requisition_id: 'req-4',
        priority: 'URGENT',
        lines: [{ item_id: 'item-2', unit: 'BOX', quantity: '20.0000', pack_size: '100', unit_price: '5000' }],
      }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('warns when a request has lines this screen cannot order', () => {
    render(
      <ProcurementIndex
        {...props}
        initialRequest={{
          id: 'req-5',
          requisitionNumber: 'PR-00000005',
          departmentRef: null,
          departmentName: null,
          priority: 'ROUTINE',
          reason: null,
          costCentreRef: null,
          budgetRef: null,
          lines: [],
          unorderableLineCount: 2,
        }}
      />,
    );

    const panel = screen.getByRole('dialog', { name: 'New purchase order' });
    expect(within(panel).getByText('2 line(s) cannot be ordered here')).toBeInTheDocument();
  });

  it('hides every write control from a reader', () => {
    render(<ProcurementIndex {...props} canCreateOrders={false} />);

    expect(screen.queryByRole('button', { name: 'New purchase order' })).not.toBeInTheDocument();
  });
});
