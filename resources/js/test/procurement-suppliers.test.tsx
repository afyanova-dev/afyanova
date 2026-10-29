import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ProcurementIndex from '@/pages/Procurement/Index';
import type { ProcurementWorkspaceProps, Supplier } from '@/features/procurement';

const { formPost, formPatch } = vi.hoisted(() => ({
  formPost: vi.fn(),
  formPatch: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');
  return {
    router: { get: vi.fn(), post: vi.fn() },
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
    useForm: (initialData: Record<string, string>) => {
      const [data, setState] = useState(initialData);
      return {
        data,
        errors: {},
        processing: false,
        setData: (keyOrData: string | Record<string, string>, value?: string) => {
          if (typeof keyOrData === 'string') {
            setState((current) => ({ ...current, [keyOrData]: value ?? '' }));
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

const suppliers: Supplier[] = [
  {
    id: 'sup-1',
    code: 'PHARMA-01',
    name: 'Acme Medical',
    registrationNumber: 'BRELA-1',
    licenceNumber: 'LIC-ACME-1',
    licenceAuthority: 'TMDA',
    licenceExpiryDate: '2027-10-22',
    licenceExpiryStatus: 'CURRENT',
    contactName: 'Neema Mushi',
    contactEmail: 'orders@acme.test',
    contactPhone: '+255 700 000 000',
    address: null,
    taxIdentifier: null,
    paymentTerms: 'NET 30',
    categories: ['CONSUMABLES', 'PHARMA'],
    status: 'ACTIVE',
    statusReason: null,
    allowedStatuses: ['SUSPENDED', 'INACTIVE', 'BLACKLISTED'],
  },
  {
    id: 'sup-2',
    code: 'LAB-02',
    name: 'Coast Labs',
    registrationNumber: null,
    licenceNumber: 'LIC-LAB-2',
    licenceAuthority: 'TMDA',
    licenceExpiryDate: '2026-10-21',
    licenceExpiryStatus: 'EXPIRED',
    contactName: null,
    contactEmail: null,
    contactPhone: null,
    address: null,
    taxIdentifier: null,
    paymentTerms: null,
    categories: [],
    status: 'SUSPENDED',
    statusReason: 'Late deliveries',
    allowedStatuses: ['ACTIVE', 'INACTIVE', 'BLACKLISTED'],
  },
  {
    id: 'sup-3',
    code: 'OLD-03',
    name: 'Gone Ltd',
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
    status: 'BLACKLISTED',
    statusReason: 'Repeated fraud',
    allowedStatuses: [],
  },
];

const props: ProcurementWorkspaceProps = {
  section: 'suppliers',
  facilityContextRequired: false,
  suppliers,
  supplierStatuses: ['ACTIVE', 'SUSPENDED', 'INACTIVE', 'BLACKLISTED'],
  canManageSuppliers: true,
  canCreateOrders: false,
  canCreateRequests: false,
  canCreateForAnyDepartment: false,
  currentUserId: 'user-1',
  purchaseOrders: [],
  purchaseRequisitions: [],
  departments: [],
  items: [],
  effectiveApprovalLimit: null,
  initialRequest: null,
  orderableRequests: [],
};

function rowFor(name: string): HTMLElement {
  const row = screen.getByText(name).closest('tr');
  if (!(row instanceof HTMLElement)) {
    throw new Error(`No table row for ${name}`);
  }

  return row;
}

describe('procurement suppliers screen', () => {
  beforeEach(() => {
    formPost.mockReset();
    formPatch.mockReset();

    globalThis.ResizeObserver ??= class {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    };
  });

  it('lists the suppliers with their status and lifecycle-backed actions', () => {
    render(<ProcurementIndex {...props} />);

    expect(screen.getByRole('heading', { name: 'Suppliers' })).toBeInTheDocument();
    expect(screen.getByText('PHARMA-01')).toBeInTheDocument();
    expect(within(rowFor('Acme Medical')).getByText('Active')).toBeInTheDocument();
    expect(within(rowFor('Acme Medical')).getByText('CONSUMABLES')).toBeInTheDocument();
    expect(within(rowFor('Coast Labs')).getByText('Suspended')).toBeInTheDocument();
    expect(within(rowFor('Gone Ltd')).getByText('Blacklisted')).toBeInTheDocument();
    expect(within(rowFor('Acme Medical')).getByText('Current')).toBeInTheDocument();
    expect(within(rowFor('Coast Labs')).getByText('Expired')).toBeInTheDocument();
    expect(within(rowFor('Gone Ltd')).getByText('Not recorded')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Manage' })).toHaveLength(3);

    expect(document.querySelector('[aria-label="Add supplier"]'))
      .toHaveAttribute('aria-hidden', 'true');
    expect(document.querySelector('[aria-label="Manage supplier"]'))
      .toHaveAttribute('aria-hidden', 'true');
  });

  it('registers a supplier from the add panel', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'New supplier' }));

    const panel = screen.getByRole('dialog', { name: 'Add supplier' });
    expect(panel).toHaveAttribute('aria-hidden', 'false');

    fireEvent.change(within(panel).getByLabelText(/Supplier name/), { target: { value: 'Acme Medical' } });
    fireEvent.change(within(panel).getByLabelText(/^Code/), { target: { value: 'acme-01' } });
    fireEvent.change(within(panel).getByLabelText(/Categories/), { target: { value: 'Pharma, Consumables' } });
    fireEvent.change(within(panel).getByLabelText(/Supplier licence number/), { target: { value: 'LIC-NEW' } });
    fireEvent.change(within(panel).getByLabelText(/Issuing authority/), { target: { value: 'TMDA' } });
    fireEvent.change(within(panel).getByLabelText(/Licence expiry date/), { target: { value: '2027-01-01' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Add supplier' }));

    expect(formPost).toHaveBeenCalledWith(
      '/workspaces/procurement/suppliers',
      expect.objectContaining({
        name: 'Acme Medical',
        code: 'acme-01',
        categories: 'Pharma, Consumables',
        licence_number: 'LIC-NEW',
        licence_authority: 'TMDA',
        licence_expiry_date: '2027-01-01',
      }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('updates licence details independently of supplier status changes', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(within(rowFor('Acme Medical')).getByRole('button', { name: 'Manage' }));

    const panel = screen.getByRole('dialog', { name: 'Manage supplier' });
    fireEvent.change(within(panel).getByLabelText(/Licence number/), { target: { value: 'LIC-UPDATED' } });
    fireEvent.change(within(panel).getByLabelText(/Issuing authority/), { target: { value: 'TMDA' } });
    fireEvent.change(within(panel).getByLabelText(/Expiry date/), { target: { value: '2028-03-15' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Save licence details' }));

    expect(formPatch).toHaveBeenCalledWith(
      '/workspaces/procurement/suppliers/sup-1/licence',
      {
        licence_number: 'LIC-UPDATED',
        licence_authority: 'TMDA',
        licence_expiry_date: '2028-03-15',
      },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('offers only the statuses the server says are allowed, and warns about blacklisting', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(within(rowFor('Coast Labs')).getByRole('button', { name: 'Manage' }));

    const panel = screen.getByRole('dialog', { name: 'Manage supplier' });
    expect(panel).toHaveAttribute('aria-hidden', 'false');
    expect(within(panel).getByText('Late deliveries')).toBeInTheDocument();

    const select = within(panel).getByLabelText(/New status/) as HTMLSelectElement;
    expect(Array.from(select.options).map((option) => option.value))
      .toEqual(['ACTIVE', 'INACTIVE', 'BLACKLISTED']);

    fireEvent.change(select, { target: { value: 'BLACKLISTED' } });
    expect(within(panel).getByText('Blacklisting cannot be undone')).toBeInTheDocument();

    fireEvent.change(within(panel).getByLabelText(/Reason/), { target: { value: 'Repeated fraud' } });
    fireEvent.click(within(panel).getByRole('button', { name: 'Update status' }));

    expect(formPatch).toHaveBeenCalledWith(
      '/workspaces/procurement/suppliers/sup-2/status',
      { target_status: 'BLACKLISTED', reason: 'Repeated fraud' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('shows a blacklisted supplier as final — no further status to pick', () => {
    render(<ProcurementIndex {...props} />);

    fireEvent.click(within(rowFor('Gone Ltd')).getByRole('button', { name: 'Manage' }));

    const panel = screen.getByRole('dialog', { name: 'Manage supplier' });
    expect(within(panel).getByText('No further changes')).toBeInTheDocument();
    expect(within(panel).queryByLabelText(/New status/)).not.toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Update status' })).toBeDisabled();
  });

  it('hides every write action from a reader without the supplier permission', () => {
    render(<ProcurementIndex {...props} canManageSuppliers={false} />);

    expect(screen.queryByRole('button', { name: 'New supplier' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Manage' })).not.toBeInTheDocument();
  });
});
